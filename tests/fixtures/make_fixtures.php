<?php
// Builds the SYNTHETIC documents the tests use (no personal data): php tests/fixtures/make_fixtures.php
require __DIR__ . '/../../app/vendor/fpdf/fpdf.php';
$lines = [[['01 Oct 2026', 'Mock Cafe', '349.00', 'DR', '9651.00'], ['02 Oct 2026', 'Mock Salary Pvt Ltd', '45000.00', 'CR', '54651.00'], ['03 Oct 2026', 'Mock Grocer', '1520.50', 'DR', '53130.50'], ['04 Oct 2026', 'Mock Fuel Station', '800.00', 'DR', '52330.50']],
    [['05 Oct 2026', 'Mock Pharmacy', '230.00', 'DR', '52100.50'], ['06 Oct 2026', 'Mock Electricity Board', '1800.00', 'DR', '50300.50'], ['07 Oct 2026', 'Mock Interest', '120.75', 'CR', '50421.25'], ['08 Oct 2026', 'Mock Bookshop', '640.00', 'DR', '49781.25']],
    [['09 Oct 2026', 'Mock Taxi', '275.00', 'DR', '49506.25'], ['10 Oct 2026', 'Mock Restaurant', '980.00', 'DR', '48526.25'], ['11 Oct 2026', 'Mock Refund Store', '199.00', 'CR', '48725.25'], ['12 Oct 2026', 'Mock Mobile Recharge', '299.00', 'DR', '48426.25']]];
$pdf = new FPDF(); $pdf->SetCompression(false); $pdf->SetFont('Helvetica', '', 11);
foreach ($lines as $i => $page) { $pdf->AddPage(); $pdf->Text(15, 20, 'Mock Bank statement, page ' . ($i + 1) . ' of 3'); $pdf->Text(15, 28, 'Account: Mock Bank 1234'); foreach ($page as $k => $l) { $pdf->Text(15, 45 + $k * 10, $l[0] . ' | ' . $l[1] . ' | ' . $l[2] . ' | ' . $l[3] . ' | ' . $l[4]); } }
$pdf->Output('F', __DIR__ . '/sample_statement.pdf');
// a one page PDF
$one = new FPDF(); $one->SetCompression(false); $one->SetFont('Helvetica', '', 11); $one->AddPage(); $one->Text(15, 20, 'Mock Bank statement, page 1 of 1'); $one->Text(15, 45, '01 Oct 2026 | Mock Cafe | 349.00 | DR | 9651.00'); $one->Output('F', __DIR__ . '/sample_onepage.pdf');
function png_with_text(string $png, array $meta): string {   // splice a tEXt chunk after IHDR
    $data = 'Description' . "\0" . json_encode($meta); $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data)); return substr($png, 0, 33) . $chunk . substr($png, 33);
}
function receipt(string $path, array $meta, array $text): void {
    $im = imagecreatetruecolor(320, 420); $bg = imagecolorallocate($im, 252, 252, 248); $ink = imagecolorallocate($im, 30, 30, 30); imagefilledrectangle($im, 0, 0, 320, 420, $bg); $f = __DIR__ . '/../../assets/fonts/DejaVuSans.ttf'; $y = 40;
    foreach ($text as $t) { if (is_file($f) && function_exists('imagettftext')) { imagettftext($im, 13, 0, 20, $y, $ink, $f, $t); } else { imagestring($im, 4, 20, $y - 12, $t, $ink); } $y += 34; }
    ob_start(); imagepng($im); file_put_contents($path, png_with_text((string) ob_get_clean(), $meta));
}
receipt(__DIR__ . '/sample_receipt.png', ['merchant' => 'Mock Cafe', 'date' => '2026-10-14', 'total' => 349, 'ref' => 'RCPT-0001'], ['MOCK CAFE', 'Receipt RCPT-0001', '14 Oct 2026', 'Masala dosa  120.00', 'Filter coffee  60.00', 'Thali  169.00', 'TOTAL  Rs 349.00']);
receipt(__DIR__ . '/sample_bill.png', ['merchant' => 'Mock Electricity Board', 'date' => '2026-11-05', 'total' => 1850, 'bill' => true], ['MOCK ELECTRICITY BOARD', 'Bill for October', 'Amount due  Rs 1850.00', 'Due date  05 Nov 2026']);
$im = imagecreatetruecolor(1, 1); ob_start(); imagepng($im); file_put_contents(__DIR__ . '/sample_blank.png', (string) ob_get_clean());
echo "fixtures written\n";
