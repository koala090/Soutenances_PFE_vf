<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'assistante', 'directeur']);

$user = get_user_info();

// Action : Générer l'attestation
if (isset($_GET['soutenance_id']) && isset($_GET['action']) && $_GET['action'] === 'generer') {
    $soutenance_id = intval($_GET['soutenance_id']);
    
    // Récupérer les infos
    $stmt = $pdo->prepare("
        SELECT s.*, p.titre,
               e.nom as etudiant_nom, e.prenom as etudiant_prenom,
               e.email as etudiant_email,
               f.nom as filiere_nom, f.code as filiere_code
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        JOIN utilisateurs e ON p.etudiant_id = e.id
        JOIN filieres f ON p.filiere_id = f.id
        WHERE s.id = ? AND s.statut = 'terminee' AND s.note_finale >= 10
    ");
    $stmt->execute([$soutenance_id]);
    $sout = $stmt->fetch();
    
    if (!$sout) {
        die("Soutenance introuvable ou note insuffisante pour une attestation");
    }
    
    require_once '../libs/fpdf/fpdf.php';
    
    class AttestationPDF extends FPDF {
        function Header() {
            // Logo ou en-tête (optionnel)
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, 'UNIVERSITE EUROMED DE FES', 0, 1, 'C');
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 8, 'Ecole d\'Ingenierie Digitale et Intelligence Artificielle', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 6, 'Route de Meknes - Fes', 0, 1, 'C');
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-25);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 5, 'Cette attestation est delivree pour servir et valoir ce que de droit.', 0, 1, 'C');
            $this->Cell(0, 5, 'Universite Euromed de Fes - EIDIA', 0, 1, 'C');
            $this->Cell(0, 5, 'www.ueuromed.org', 0, 0, 'C');
        }
    }
    
    $pdf = new AttestationPDF();
    $pdf->AddPage();
    
    $pdf->Ln(15);
    
    // Titre
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 12, 'ATTESTATION DE REUSSITE', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Ligne décorative
    $pdf->SetLineWidth(0.5);
    $pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
    $pdf->Ln(15);
    
    // Corps de l'attestation
    $pdf->SetFont('Arial', '', 12);
    
    $pdf->Cell(0, 8, 'Le Directeur de l\'Ecole d\'Ingenierie Digitale et Intelligence Artificielle', 0, 1, 'C');
    $pdf->Cell(0, 8, 'de l\'Universite Euromed de Fes', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'ATTESTE QUE', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Informations étudiant - Cadre
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 14);
    
    $nom_complet = strtoupper($sout['etudiant_nom']) . ' ' . ucfirst(strtolower($sout['etudiant_prenom']));
    $pdf->Cell(0, 12, utf8_decode($nom_complet), 0, 1, 'C', true);
    $pdf->Ln(8);
    
    // Texte principal
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 7, utf8_decode("a soutenu avec succès son Projet de Fin d'Études intitulé :"), 0, 'C');
    $pdf->Ln(5);
    
    // Titre du projet en italique
    $pdf->SetFont('Arial', 'I', 12);
    $pdf->MultiCell(0, 7, utf8_decode('"' . $sout['titre'] . '"'), 0, 'C');
    $pdf->Ln(8);
    
    // Détails de la soutenance
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 7, utf8_decode("le " . date('d/m/Y', strtotime($sout['date_soutenance'])) . 
                                       " devant un jury composé de professeurs de l'établissement."), 0, 'C');
    $pdf->Ln(8);
    
    // Résultats - Cadre
    $pdf->SetFillColor(230, 250, 230);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 10, 'NOTE OBTENUE : ' . number_format($sout['note_finale'], 2) . ' / 20', 0, 1, 'C', true);
    
    if ($sout['mention']) {
        $mentions_fr = [
            'passable' => 'Passable',
            'assez_bien' => 'Assez Bien',
            'bien' => 'Bien',
            'tres_bien' => 'Tres Bien',
            'excellent' => 'Excellent'
        ];
        
        $pdf->Cell(0, 10, 'MENTION : ' . strtoupper($mentions_fr[$sout['mention']]), 0, 1, 'C', true);
    }
    $pdf->Ln(15);
    
    // Formule de clôture
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, utf8_decode("En foi de quoi, la présente attestation lui est délivrée pour servir et valoir ce que de droit."), 0, 'C');
    $pdf->Ln(15);
    
    // Date et lieu
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, utf8_decode('Fait à Fès, le ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->Ln(8);
    
    // Signatures
    $y_sign = $pdf->GetY();
    
    // Signature gauche (Coordinateur)
    $pdf->SetXY(30, $y_sign);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(70, 6, 'Le Coordinateur de Filiere', 0, 1, 'C');
    $pdf->SetX(30);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(70, 6, utf8_decode($sout['filiere_nom']), 0, 1, 'C');
    $pdf->Ln(15);
    $pdf->SetX(30);
    $pdf->Cell(70, 6, '', 'T', 0, 'C');
    
    // Signature droite (Directeur)
    $pdf->SetXY(110, $y_sign);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(70, 6, 'Le Directeur de l\'EIDIA', 0, 1, 'C');
    $pdf->SetX(110);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(70, 6, 'UEUROMED', 0, 1, 'C');
    $pdf->Ln(15);
    $pdf->SetX(110);
    $pdf->Cell(70, 6, '', 'T', 0, 'C');
    
    // Cachet (placeholder)
    $pdf->SetXY(85, $y_sign + 25);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(40, 6, '(Cachet officiel)', 0, 0, 'C');
    
    // Enregistrer
    $filename = 'ATTESTATION_' . strtoupper($sout['etudiant_nom']) . '_' . date('Ymd') . '.pdf';
    $filepath = '../uploads/attestations/' . $filename;
    
    if (!is_dir('../uploads/attestations')) {
        mkdir('../uploads/attestations', 0755, true);
    }
    
    $pdf->Output('F', $filepath);
    
    // Télécharger
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($filepath);
    exit;
}

// Liste des soutenances réussies
$where_clause = "WHERE s.statut = 'terminee' AND s.note_finale >= 10";
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clause .= " AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    $where_clause
    ORDER BY s.date_soutenance DESC
");
$stmt->execute($params);
$soutenances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attestations de Réussite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-award"></i> Attestations de Réussite</h1>
            <a href="../dashboards/<?= $user['role'] ?>.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="bi bi-info-circle"></i> Information
            </div>
            <div class="card-body">
                <p class="mb-0">
                    Les attestations de réussite sont délivrées aux étudiants ayant obtenu une note finale 
                    supérieure ou égale à 10/20. Ce document officiel certifie la réussite du Projet de Fin d'Études.
                </p>
            </div>
        </div>

        <?php if (count($soutenances) > 0): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-check-circle"></i> 
                        Étudiants ayant réussi (<?= count($soutenances) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Étudiant</th>
                                    <th>Projet</th>
                                    <th>Filière</th>
                                    <th>Note</th>
                                    <th>Mention</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soutenances as $sout): ?>
                                    <tr>
                                        <td><?= formater_date($sout['date_soutenance'], 'd/m/Y') ?></td>
                                        <td><strong><?= htmlspecialchars($sout['etudiant_nom']) ?></strong></td>
                                        <td><?= htmlspecialchars(substr($sout['titre'], 0, 40)) ?>...</td>
                                        <td><?= htmlspecialchars($sout['filiere_nom']) ?></td>
                                        <td>
                                            <span class="badge bg-success">
                                                <?= number_format($sout['note_finale'], 2) ?>/20
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($sout['mention']): ?>
                                                <?php
                                                $mentions = [
                                                    'passable' => ['text' => 'Passable', 'bg' => 'secondary'],
                                                    'assez_bien' => ['text' => 'Assez Bien', 'bg' => 'info'],
                                                    'bien' => ['text' => 'Bien', 'bg' => 'primary'],
                                                    'tres_bien' => ['text' => 'Très Bien', 'bg' => 'warning'],
                                                    'excellent' => ['text' => 'Excellent', 'bg' => 'success']
                                                ];
                                                $m = $mentions[$sout['mention']];
                                                ?>
                                                <span class="badge bg-<?= $m['bg'] ?>"><?= $m['text'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                               class="btn btn-sm btn-success">
                                                <i class="bi bi-file-earmark-pdf"></i> Générer attestation
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Aucune soutenance réussie pour le moment.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>