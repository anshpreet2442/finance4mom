<?php
declare(strict_types=1);
/** Attachments for the AI chat: validated uploads (type sniffed from the bytes, size and count limits), turned into content blocks for the model. */

const AI_UPLOAD_MAX = 5 * 1024 * 1024;       // per file
const AI_UPLOAD_PER_MESSAGE = 4;
const AI_UPLOAD_PER_HOUR = 20;
const AI_UPLOAD_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv'];
function ai_upload_dir(): string { $d = rtrim(sys_get_temp_dir(), '/') . '/mfnc-ai-uploads'; if (!is_dir($d)) { @mkdir($d, 0700, true); } return $d; }
function ai_upload_limits(): array { return ['max_bytes' => AI_UPLOAD_MAX, 'max_files' => AI_UPLOAD_PER_MESSAGE, 'types' => array_keys(AI_UPLOAD_TYPES), 'accept' => 'image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,.txt,.csv']; }
/** Cleans leftovers older than a day (called opportunistically on upload). */
function ai_upload_gc(): void {
    foreach (all('SELECT id,path FROM {ai_uploads} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]) as $r) { @unlink(ai_upload_dir() . '/' . basename($r['path'])); run('DELETE FROM {ai_uploads} WHERE id=?', [$r['id']]); }
}
/** Stores one uploaded file (a $_FILES entry, or ['tmp_name','name','size'] in tests). Returns the row summary. Throws UserError with a friendly reason. */
function ai_store_upload(array $u, array $f): array {
    $uid = (int) $u['id']; $err = (int) ($f['error'] ?? 0);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { throw new UserError('That file is too big (limit ' . (AI_UPLOAD_MAX >> 20) . ' MB).'); }
    if ($err !== 0 || empty($f['tmp_name']) || !is_file($f['tmp_name'])) { throw new UserError('The upload did not finish. Try again.'); }
    $size = (int) filesize($f['tmp_name']); if ($size <= 0) { throw new UserError('That file is empty.'); } if ($size > AI_UPLOAD_MAX) { throw new UserError('That file is too big (limit ' . (AI_UPLOAD_MAX >> 20) . ' MB).'); }
    if ((int) val('SELECT COUNT(*) FROM {ai_uploads} WHERE user_id=? AND created_at >= ?', [$uid, gmdate('Y-m-d H:i:s', time() - 3600)]) >= AI_UPLOAD_PER_HOUR) { throw new UserError('Too many uploads this hour. Try again later.'); }
    $fi = new finfo(FILEINFO_MIME_TYPE); $mime = (string) $fi->file($f['tmp_name']);
    if ($mime === 'text/x-csv' || $mime === 'application/csv') { $mime = 'text/csv'; }
    if (!isset(AI_UPLOAD_TYPES[$mime])) { throw new UserError('That file type is not supported. Use a photo (JPG, PNG, GIF, WebP), a PDF, or a text/CSV file.'); }
    if (str_starts_with($mime, 'text/')) { $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 4096); if (str_contains($head, "\0") || !mb_check_encoding($head, 'UTF-8')) { throw new UserError('That text file is not valid UTF-8 text.'); } }
    $name = trim(preg_replace('/[^\p{L}\p{N} ._\-()]/u', '', basename((string) ($f['name'] ?? 'file'))) ?? ''); $name = mb_substr($name !== '' ? $name : 'file', 0, 80);
    $path = bin2hex(random_bytes(16)) . '.' . AI_UPLOAD_TYPES[$mime]; $dest = ai_upload_dir() . '/' . $path;
    $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dest) : copy($f['tmp_name'], $dest); if (!$ok) { throw new UserError('Could not save the file.'); } @chmod($dest, 0600);
    $id = insert('INSERT INTO {ai_uploads} (user_id,name,mime,size,path) VALUES (?,?,?,?,?)', [$uid, $name, $mime, $size, $path]); if (random_int(1, 10) === 1) { ai_upload_gc(); }
    return ['id' => $id, 'name' => $name, 'mime' => $mime, 'size' => $size];
}
/** Loads this person's uploads by id (others' ids are ignored). */
function ai_load_uploads(int $uid, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0))); if (count($ids) > AI_UPLOAD_PER_MESSAGE) { throw new UserError('Attach at most ' . AI_UPLOAD_PER_MESSAGE . ' files per message.'); }
    $o = []; foreach ($ids as $id) { $r = one('SELECT * FROM {ai_uploads} WHERE id=? AND user_id=?', [$id, $uid]); if ($r) { $o[] = $r; } } return $o;
}
/** Content blocks for the model. Images/PDFs only when the model can see them; otherwise a short note. Text files are inlined (truncated). Files are deleted once used. */
function ai_upload_blocks(array $rows, bool $vision): array {
    $blocks = []; $notes = [];
    foreach ($rows as $r) {
        $file = ai_upload_dir() . '/' . basename($r['path']); $raw = is_file($file) ? (string) file_get_contents($file) : '';
        if ($raw === '') { $notes[] = $r['name'] . ' (no longer available)'; continue; }
        if (str_starts_with($r['mime'], 'image/')) { if ($vision) { $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $r['mime'], 'data' => base64_encode($raw)]]; } else { $notes[] = $r['name'] . ' (this model cannot look at images)'; } }
        elseif ($r['mime'] === 'application/pdf') { if ($vision) { $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($raw)], 'title' => $r['name']]; } else { $notes[] = $r['name'] . ' (this model cannot read PDFs)'; } }
        else { $blocks[] = ['type' => 'text', 'text' => 'Attached file "' . $r['name'] . '":' . "\n" . mb_substr($raw, 0, 20000)]; }
    }
    if ($notes) { $blocks[] = ['type' => 'text', 'text' => 'Note: these attachments could not be shown to you: ' . implode('; ', $notes) . '. Tell the person if it matters.']; }
    return $blocks;
}
function ai_upload_discard(array $rows): void { foreach ($rows as $r) { @unlink(ai_upload_dir() . '/' . basename($r['path'])); run('DELETE FROM {ai_uploads} WHERE id=?', [$r['id']]); } }
