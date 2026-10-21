<?php
declare(strict_types=1);
/**
 * Round 4 (builder-E): request guard for every model call.
 *  - ai_validate_request(): checks a Messages API request body the way the real API does (tool_use.input is an object, every tool_result follows its tool_use, roles alternate and start with
 *    "user", no empty text blocks, max_tokens > thinking budget, tool schemas are objects, image/document blocks carry real base64 of the right type). The mock transports call it first and fail with
 *    the API's own invalid_request_error, so a whole class of "works in tests, 400 on the real API" bugs is caught by the test-suite in every path (web chat, Telegram, layout, documents).
 *  - ai_fix_body(): the real transport's last line of defence (repairs the two things PHP gets wrong: empty tool input {} decoded to [], and empty text blocks).
 *  - AiApiError: a UserError that remembers the API's error type and message (admins see the message; users get the friendly line).
 *  - ai_redact(): removes anything that looks like a key from text that may be shown or logged.
 */

class AiApiError extends UserError {
    public string $apiType = ''; public string $apiMessage = ''; public int $http = 0;
    public static function make(int $http, string $type, string $apiMessage, string $friendly): self { $e = new self($friendly); $e->http = $http; $e->apiType = $type; $e->apiMessage = ai_redact($apiMessage); return $e; }
}

/** Removes API keys, bearer tokens, long opaque tokens and the text of the admin's own key from a message. */
function ai_redact(string $s): string {
    $s = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[key]', $s) ?? $s;
    $s = preg_replace('/(bearer|x-api-key|api[_-]?key|authorization)\s*[:=]?\s*[A-Za-z0-9._\-]{12,}/i', '$1 [hidden]', $s) ?? $s;
    $s = preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[hidden]', $s) ?? $s;
    try { $k = function_exists('ai_cfg') ? (string) ai_cfg()['key'] : ''; if (strlen($k) >= 8) { $s = str_replace($k, '[key]', $s); } } catch (Throwable) {}
    return mb_substr($s, 0, 600);
}

/** The exception the real transport throws for an error response, shared with the mock so both behave identically. */
function ai_api_error(int $http, array $r): AiApiError {
    $t = (string) ($r['error']['type'] ?? ''); $m = (string) ($r['error']['message'] ?? '');
    $friendly = match (true) { $http === 401 || $http === 403 => 'The AI service rejected the API key. The administrator needs to check it.', $http === 429 || $http === 529 => 'The AI service is busy right now. Try again in a minute.',
        $http === 404 => 'The selected AI model is not available to this API key. The administrator can pick another model.',
        default => 'The AI service returned an error (' . ($t ?: "HTTP $http") . ').' . ((($cu = current_user()) && ($cu['role'] ?? '') === 'admin' && $m !== '') ? ' Details for administrators: ' . mb_substr(ai_redact($m), 0, 300) : '') };
    return AiApiError::make($http, $t, $m, $friendly);
}

/** Repairs what json_decode() breaks when a response is sent back: tool_use.input [] -> {}, and drops empty text blocks. Returns the same shape. */
function ai_fix_body(array $body): array {
    foreach ($body['messages'] ?? [] as $i => $m) {
        if (!is_array($m['content'] ?? null)) { continue; }
        $c = ai_fix_content($m['content']);
        if (($m['role'] ?? '') === 'user' || ($m['role'] ?? '') === 'assistant') { $c = array_values(array_filter($c, fn($b) => !(is_array($b) && ($b['type'] ?? '') === 'text' && trim((string) ($b['text'] ?? '')) === ''))); }
        $body['messages'][$i]['content'] = $c ?: [['type' => 'text', 'text' => '(no content)']];
    }
    return $body;
}

/** Returns null when the request would be accepted, otherwise the message the API would send in its invalid_request_error. */
function ai_validate_request(array $body): ?string {
    $json = json_encode($body, JSON_PRESERVE_ZERO_FRACTION); if ($json === false) { return 'The request body is not valid JSON (' . json_last_error_msg() . ').'; }
    if (strlen($json) > 32 * 1048576) { return 'request_too_large: the request is over 32 MB.'; }
    $w = json_decode($json, false, 512);
    if (!is_object($w)) { return 'The request body must be a JSON object.'; }
    if (!isset($w->model) || !is_string($w->model) || $w->model === '') { return 'model: Field required'; }
    if (!isset($w->max_tokens) || !is_int($w->max_tokens) || $w->max_tokens < 1) { return 'max_tokens: Input should be a valid integer greater than or equal to 1'; }
    // ---- thinking / output config ----
    if (isset($w->thinking)) {
        if (!is_object($w->thinking) || !in_array($w->thinking->type ?? '', ['enabled', 'adaptive', 'disabled'], true)) { return 'thinking.type: Input should be \'enabled\', \'adaptive\' or \'disabled\''; }
        if ($w->thinking->type === 'enabled') {
            $b = $w->thinking->budget_tokens ?? null;
            if (!is_int($b) || $b < 1024) { return 'thinking.enabled.budget_tokens: Input should be greater than or equal to 1024'; }
            if ($w->max_tokens <= $b) { return 'max_tokens: `max_tokens` must be greater than `thinking.budget_tokens`'; }
        }
    }
    if (isset($w->output_config)) {
        if (!is_object($w->output_config)) { return 'output_config: Input should be a valid dictionary'; }
        if (isset($w->output_config->effort) && !in_array($w->output_config->effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) { return 'output_config.effort: Input should be \'low\', \'medium\', \'high\', \'xhigh\' or \'max\''; }
    }
    // ---- system ----
    if (isset($w->system)) {
        if (is_array($w->system)) { foreach ($w->system as $i => $b) { if (!is_object($b) || ($b->type ?? '') !== 'text' || !is_string($b->text ?? null) || trim($b->text) === '') { return "system.$i: text content blocks must be non-empty"; } } }
        elseif (!is_string($w->system)) { return 'system: Input should be a valid string'; }
    }
    // ---- tools ----
    $toolNames = [];
    if (isset($w->tools)) {
        if (!is_array($w->tools)) { return 'tools: Input should be a valid list'; }
        foreach ($w->tools as $i => $t) {
            if (!is_object($t)) { return "tools.$i: Input should be a valid dictionary"; }
            if (isset($t->type) && $t->type !== 'custom') { if (!is_string($t->type) || !is_string($t->name ?? null)) { return "tools.$i: server tools need a type and a name"; } $toolNames[$t->name] = true; continue; }
            $n = $t->name ?? null; if (!is_string($n) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $n)) { return "tools.$i.custom.name: String should match pattern '^[a-zA-Z0-9_-]{1,128}$'"; }
            if (isset($toolNames[$n])) { return "tools: Tool names must be unique. Found duplicate: $n"; } $toolNames[$n] = true;
            if (!is_object($t->input_schema ?? null) || ($t->input_schema->type ?? '') !== 'object') { return "tools.$i.custom.input_schema: Input should be a valid dictionary with type 'object'"; }
            if ($e = ai_schema_problem($t->input_schema, "tools.$i.custom.input_schema")) { return $e; }
            if (isset($t->description) && !is_string($t->description)) { return "tools.$i.custom.description: Input should be a valid string"; }
        }
    }
    if (isset($w->tool_choice)) {
        $tc = $w->tool_choice; if (!is_object($tc) || !in_array($tc->type ?? '', ['auto', 'any', 'tool', 'none'], true)) { return 'tool_choice.type: Input should be \'auto\', \'any\', \'tool\' or \'none\''; }
        if ($tc->type === 'tool' && (!is_string($tc->name ?? null) || !isset($toolNames[$tc->name]))) { return 'tool_choice.tool.name: Tool \'' . (is_string($tc->name ?? null) ? $tc->name : '') . '\' not found in provided tools'; }
        if (in_array($tc->type, ['any', 'tool'], true) && isset($w->thinking) && ($w->thinking->type ?? '') !== 'disabled') { return 'tool_choice: Thinking may not be enabled when tool_choice forces tool use.'; }
    }
    // ---- messages ----
    if (!isset($w->messages) || !is_array($w->messages) || !$w->messages) { return 'messages: List should have at least 1 item after validation, not 0'; }
    $prev = null; $pending = []; $seenIds = [];
    foreach ($w->messages as $i => $m) {
        if (!is_object($m) || !in_array($m->role ?? '', ['user', 'assistant'], true)) { return "messages.$i.role: Input should be 'user' or 'assistant'"; }
        if ($i === 0 && $m->role !== 'user') { return 'messages: first message must use the "user" role'; }
        if ($prev !== null && $prev === $m->role) { return "messages.$i.role: roles must alternate between \"user\" and \"assistant\", but found multiple \"{$m->role}\" roles in a row"; }
        $c = $m->content ?? null;
        if (is_string($c)) { if (trim($c) === '') { return "messages.$i: all messages must have non-empty content except for the optional final assistant message"; } $content = []; }
        elseif (is_array($c)) { if (!$c) { return "messages.$i.content: List should have at least 1 item after validation, not 0"; } $content = $c; }
        else { return "messages.$i.content: Input should be a valid string or list"; }
        // a user message right after tool_use blocks must answer every one of them, first
        if ($pending) {
            if ($m->role !== 'user') { return "messages.$i: `tool_use` ids were found without `tool_result` blocks immediately after: " . implode(', ', array_keys($pending)); }
            $got = []; $seenText = false;
            foreach ($content as $b) { if (is_object($b) && ($b->type ?? '') === 'tool_result') { if ($seenText) { return "messages.$i: `tool_result` blocks must come first in the content, before any text"; } $got[(string) ($b->tool_use_id ?? '')] = true; } else { $seenText = true; } }
            foreach ($pending as $id => $_) { if (!isset($got[$id])) { return "messages.$i: `tool_use` ids were found without `tool_result` blocks immediately after: $id. Each `tool_use` block must have a corresponding `tool_result` block in the next message."; } }
            $pending = [];
        }
        foreach ($content as $j => $b) {
            $at = "messages.$i.content.$j";
            if (!is_object($b) || !is_string($b->type ?? null)) { return "$at: Input should be a valid dictionary with a type"; }
            switch ($b->type) {
                case 'text':
                    if (!is_string($b->text ?? null) || trim($b->text) === '') { return "$at.text.text: text content blocks must be non-empty"; } break;
                case 'tool_use':
                    if ($m->role !== 'assistant') { return "$at: tool_use blocks are only allowed in assistant messages"; }
                    $id = $b->id ?? null; if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) { return "$at.tool_use.id: String should match pattern '^[a-zA-Z0-9_-]+$'"; }
                    if (isset($seenIds[$id])) { return "$at.tool_use.id: `tool_use` ids must be unique"; } $seenIds[$id] = true;
                    if (!is_string($b->name ?? null) || $b->name === '') { return "$at.tool_use.name: Field required"; }
                    if ($toolNames && !isset($toolNames[$b->name])) { return "$at.tool_use.name: tool '{$b->name}' is not defined in tools"; }
                    if (!isset($b->input) || !is_object($b->input)) { return "$at.tool_use.input: Input should be a valid dictionary"; }
                    $pending[$id] = true; break;
                case 'tool_result':
                    if ($m->role !== 'user') { return "$at: tool_result blocks are only allowed in user messages"; }
                    $tid = $b->tool_use_id ?? null; $prevM = $w->messages[$i - 1] ?? null; $ok = false;
                    if (is_string($tid) && is_object($prevM) && is_array($prevM->content ?? null)) { foreach ($prevM->content as $pb) { if (is_object($pb) && ($pb->type ?? '') === 'tool_use' && ($pb->id ?? '') === $tid) { $ok = true; } } }
                    if (!$ok) { return "$at: unexpected `tool_use_id` found in `tool_result` blocks: " . (is_string($tid) ? $tid : '(missing)') . '. Each `tool_result` block must have a corresponding `tool_use` block in the previous message.'; }
                    if (isset($b->content) && !is_string($b->content) && !is_array($b->content)) { return "$at.tool_result.content: Input should be a valid string or list"; }
                    if (isset($b->content) && is_string($b->content) && trim($b->content) === '' && empty($b->is_error)) { return "$at.tool_result.content: text content blocks must be non-empty"; }
                    if (isset($b->is_error) && !is_bool($b->is_error)) { return "$at.tool_result.is_error: Input should be a valid boolean"; }
                    break;
                case 'image':
                    if ($e = ai_media_problem($b, $at, true)) { return $e; } break;
                case 'document':
                    if ($e = ai_media_problem($b, $at, false)) { return $e; } break;
                case 'thinking': case 'redacted_thinking': case 'server_tool_use': case 'web_search_tool_result': break;
                default: return "$at.type: Input tag '{$b->type}' found using 'type' does not match any of the expected tags";
            }
        }
        $prev = $m->role;
    }
    if ($pending) { return 'messages: `tool_use` ids were found without `tool_result` blocks immediately after: ' . implode(', ', array_keys($pending)); }
    return null;
}
/** JSON-schema sanity: "properties" must be an object, "required" a list of strings, "enum" a non-empty list. */
function ai_schema_problem(object $s, string $at): ?string {
    foreach ($s as $k => $v) {
        if ($k === 'properties') { if (!is_object($v)) { return "$at.properties: Input should be a valid dictionary (an empty list [] is not an object)"; } foreach ($v as $pk => $pv) { if (!is_object($pv)) { return "$at.properties.$pk: Input should be a valid dictionary"; } if ($e = ai_schema_problem($pv, "$at.properties.$pk")) { return $e; } } }
        elseif ($k === 'required') { if (!is_array($v)) { return "$at.required: Input should be a valid list"; } foreach ($v as $r) { if (!is_string($r)) { return "$at.required: every item must be a string"; } } }
        elseif ($k === 'enum') { if (!is_array($v) || !$v) { return "$at.enum: Input should be a non-empty list"; } }
        elseif ($k === 'items' && is_object($v)) { if ($e = ai_schema_problem($v, "$at.items")) { return $e; } }
        elseif ($k === 'type' && !is_string($v) && !is_array($v)) { return "$at.type: Input should be a string"; }
    }
    return null;
}
/** image / document block checks: base64 source of a supported type whose bytes really are that type. */
function ai_media_problem(object $b, string $at, bool $image): ?string {
    $s = $b->source ?? null; $kind = $image ? 'image' : 'document';
    if (!is_object($s)) { return "$at.$kind.source: Field required"; }
    if (($s->type ?? '') === 'text' && !$image) { return is_string($s->data ?? null) && $s->data !== '' ? null : "$at.document.source.text.data: Field required"; }
    if (($s->type ?? '') !== 'base64') { return "$at.$kind.source.type: Input should be 'base64'" . ($image ? " or 'url'" : ''); }
    $mt = (string) ($s->media_type ?? ''); $data = $s->data ?? null;
    $allowed = $image ? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] : ['application/pdf'];
    if (!in_array($mt, $allowed, true)) { return "$at.$kind.source.base64.media_type: Input should be " . implode(', ', array_map(fn($x) => "'$x'", $allowed)); }
    if (!is_string($data) || $data === '' || !preg_match('~^[A-Za-z0-9+/]+={0,2}$~', $data) || strlen($data) % 4 === 1) { return "$at.$kind.source.base64.data: The $kind was specified using the base64 source type, but the data is not valid base64"; }
    $raw = base64_decode($data, true); if ($raw === false || $raw === '') { return "$at.$kind.source.base64.data: invalid base64 data"; }
    if ($image) {
        if (strlen($raw) > 5 * 1048576) { return "$at.image.source.base64: image exceeds 5 MB maximum"; }
        $ok = match ($mt) { 'image/jpeg' => str_starts_with($raw, "\xFF\xD8\xFF"), 'image/png' => str_starts_with($raw, "\x89PNG\r\n\x1a\n"), 'image/gif' => str_starts_with($raw, 'GIF8'), 'image/webp' => str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP', default => false };
        if (!$ok) { return "$at.image.source.base64: Image does not match the provided media type $mt"; }
    } else {
        if (!str_starts_with($raw, '%PDF-')) { return "$at.document.source.base64.data: The PDF specified was not valid (no %PDF- header)"; }
        if (strlen($raw) > 32 * 1048576) { return "$at.document.source.base64: PDF exceeds 32 MB maximum"; }
        if (function_exists('ai_pdf_pages') && ($pg = ai_pdf_pages($raw)) !== null && $pg > 100) { return "$at.document.source.base64: A maximum of 100 PDF pages may be provided ($pg given)"; }
    }
    return null;
}
/** Used by every mock transport: validate, and fail exactly like the real API would. */
function ai_mock_guard(array $body): void {
    $why = ai_validate_request($body); if ($why === null) { return; }
    $GLOBALS['__ai_mock_last_invalid'] = $why;
    throw ai_api_error(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $why]]);
}
