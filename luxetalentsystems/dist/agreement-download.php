<?php
/* Luxe Talent — signed Model Agreement download (SEPARATE from consent-download.php)
 * Outputs the full Talent Representation & Content Agency Agreement,
 * merged with the model's registration info + signature, as a PDF.
 */
require __DIR__ . '/../config.php';

$email = isset($_GET['e']) ? trim($_GET['e']) : '';
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if ($email === '') { http_response_code(400); exit('Missing email'); }

// Pull the model's registration record
$stmt = db()->prepare(
  'SELECT first_name, middle_name, last_name, stage_name, email, phone,
          street_address, city, state, zip_code, country, date_of_birth,
          signature_path, printed_name, signed_at, signed_ip
   FROM registration WHERE email = ?'
);
$stmt->execute([$email]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Registration not found'); }

// Agency values (from settings if available, else sensible defaults)
$agency_name = 'Luxe Model Collective';
$agency_addr = '100 M St SE, Washington, DC 20003';
$agency_pct  = '24';
$model_pct   = '76';
$term_days   = '30';
$gov_state   = 'District of Columbia';
$missed      = '3';
try {
  $raw = db()->query("SELECT setting_value FROM settings WHERE setting_key='reg_config'")->fetchColumn();
  if ($raw) {
    $cfg = json_decode($raw, true);
    if (isset($cfg['agency_percentage'])) { $agency_pct = (string)$cfg['agency_percentage']; $model_pct = (string)(100 - (int)$cfg['agency_percentage']); }
    if (!empty($cfg['agency_name']))     $agency_name = $cfg['agency_name'];
    if (!empty($cfg['governing_state'])) $gov_state = $cfg['governing_state'];
    if (!empty($cfg['contract_term_days'])) $term_days = (string)$cfg['contract_term_days'];
    if (!empty($cfg['missed_shoots_number'])) $missed = (string)$cfg['missed_shoots_number'];
  }
} catch (Throwable $e) {}

$name = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'].' ' : '') . $r['last_name']);
$model_addr = trim($r['street_address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['zip_code'] . ', ' . $r['country']);

require_once __DIR__ . '/fpdf.php';

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell(0, 10, $agency_name, 0, 1);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Talent Representation & Content Agency Agreement', 0, 1);
$pdf->Ln(1);
$pdf->Cell(0, 0, '', 'T', 1);
$pdf->Ln(5);

// Parties
$pdf->SetFont('Helvetica', '', 10);
$pdf->MultiCell(0, 5, 'This Agreement is entered into as of ' . ($r['signed_at'] ?: date('Y-m-d')) . ' by and between ' . $agency_name . ' ("Agency"), ' . $agency_addr . ', and ' . $name . ' ("Model").');
$pdf->Ln(3);

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Model', 0, 1);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(40, 5, 'Name:', 0, 0);       $pdf->Cell(0, 5, $name, 0, 1);
if ($r['stage_name']) { $pdf->Cell(40, 5, 'Stage Name:', 0, 0); $pdf->Cell(0, 5, $r['stage_name'], 0, 1); }
$pdf->Cell(40, 5, 'Address:', 0, 0);    $pdf->Cell(0, 5, $model_addr, 0, 1);
$pdf->Cell(40, 5, 'Email:', 0, 0);      $pdf->Cell(0, 5, $r['email'], 0, 1);
$pdf->Cell(40, 5, 'Phone:', 0, 0);      $pdf->Cell(0, 5, $r['phone'], 0, 1);
$pdf->Cell(40, 5, 'Date of Birth:', 0, 0); $pdf->Cell(0, 5, $r['date_of_birth'], 0, 1);
$pdf->Ln(4);

// Terms
$clauses = [
  ['1. Age Verification (18+)', 'The Model represents and certifies they are at least eighteen (18) years of age, and all identification and date-of-birth information provided is true and accurate. Any misrepresentation of age voids this Agreement immediately. The Agency maintains age-verification records per applicable law (including 18 U.S.C. 2257 where applicable).'],
  ['2. Appointment', 'The Model appoints ' . $agency_name . ' to represent them in matters regarding modeling, content creation, camming, contracts, and related business for the platforms coordinated by the Agency.'],
  ['3. Consent to Adult Content', 'The Model acknowledges the services involve the creation, performance, streaming, and distribution of adult-oriented content, entered into freely and voluntarily, and may communicate limits at any time.'],
  ['4. Agency Compensation', 'The Agency shall retain ' . $agency_pct . '% of all monies, tokens, fees, and tips received under this Agreement. The Model shall receive the remaining ' . $model_pct . '%. Where a platform pays the Agency directly, the Agency accounts to the Model for the Model\'s ' . $model_pct . '% share.'],
  ['5. Content Ownership', 'The Model retains rights to their likeness and grants the Agency a license to use, publish, and promote the Model\'s name, stage name, and content for the duration of the term.'],
  ['6. Independent Contractor', 'For taxes and regulations, the Model is an independent contractor responsible for their own fees.'],
  ['7. Term', 'This Agreement commences on registration and continues for ' . $term_days . ' days, extendable by written agreement of both Parties.'],
  ['8. Professional Conduct', 'Both Parties will conduct themselves professionally. The Agency may terminate on thirty (30) days notice for breach; the Model may terminate immediately for Agency breach.'],
  ['9. Confidentiality', 'Both Parties agree to maintain strict confidentiality except as required by law.'],
  ['10. Termination', 'Either Party may sever this Agreement by written notice. If the Model terminates, no prior notice is required. If the Agency terminates, it continues services for no more than thirty (30) days.'],
  ['11. Work Responsibilities', 'If the Model misses scheduled sessions (streams, bookings, or content commitments) on ' . $missed . ' occasions without prior and proper notice, the Agency reserves the right to terminate without prior notice.'],
  ['12. Governing Law', 'Disputes will first be settled amicably; failing that, in the courts of the ' . $gov_state . '.'],
];
foreach ($clauses as $c) {
  if ($pdf->GetY() > 250) { $pdf->AddPage(); }
  $pdf->SetFont('Helvetica', 'B', 10);
  $pdf->Cell(0, 6, $c[0], 0, 1);
  $pdf->SetFont('Helvetica', '', 10);
  $pdf->MultiCell(0, 5, $c[1]);
  $pdf->Ln(2);
}

// Signature block
if ($pdf->GetY() > 210) { $pdf->AddPage(); }
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 7, 'Agreed and Accepted', 0, 1);
$pdf->SetFont('Helvetica', '', 10);
$pdf->MultiCell(0, 5, 'By signing below, the Model acknowledges they have read, understood, and agree to the terms of this Agreement.');
$pdf->Ln(4);

$sigFile = __DIR__ . '/../' . ltrim((string)$r['signature_path'], '/');
if ($r['signature_path'] && is_file($sigFile)) {
  $pdf->Image($sigFile, 20, $pdf->GetY(), 70);
  $pdf->Ln(30);
} else {
  $pdf->SetFont('Helvetica', 'I', 10);
  $pdf->Cell(0, 6, '(signature image unavailable)', 0, 1);
}
if (!empty($r['printed_name'])) {
  $pdf->SetFont('Helvetica', '', 10);
  $pdf->Cell(40, 6, 'Printed Name:', 0, 0);
  $pdf->SetFont('Helvetica', 'B', 10);
  $pdf->Cell(0, 6, $r['printed_name'], 0, 1);
  $pdf->SetFont('Helvetica', '', 10);
}
$pdf->SetDrawColor(120,120,120);
$pdf->Cell(80, 0, '', 'T', 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(90,90,90);
$pdf->Cell(0, 6, trim('Signed: ' . ($r['signed_at'] ?: '')), 0, 1);
if ($r['signed_ip']) { $pdf->Cell(0, 6, 'IP address at signing: ' . $r['signed_ip'], 0, 1); }
$pdf->SetTextColor(0,0,0);
$pdf->Ln(2);
$pdf->Cell(0, 6, 'Agency: ' . $agency_name, 0, 1);

$pdf->Output('D', 'Model-Agreement-' . preg_replace('/[^a-z0-9]/i','',$r['last_name']) . '.pdf');
