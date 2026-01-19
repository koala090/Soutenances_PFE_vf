<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'assistante', 'professeur']);

$user = get_user_info();

// Action : Générer la grille
if (isset($_GET['soutenance_id']) && isset($_GET['action']) && $_GET['action'] === 'generer') {
    $soutenance_id = intval($_GET['soutenance_id']);
    
    // Récupérer les infos
    $stmt = $pdo->prepare("
        SELECT s.*, p.titre, p.description,
               CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
               CONCAT(b.prenom, ' ', b.nom) as binome_nom,
               f.nom as filiere_nom, f.code as filiere_code
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN utilisateurs b ON p.binome_id = b.id
        JOIN filieres f ON p.filiere_id = f.id
        WHERE s.id = ?
    ");
    $stmt->execute([$soutenance_id]);
    $sout = $stmt->fetch();
    
    if (!$sout) {
        die("Soutenance introuvable");
    }
    
    require_once '../libs/fpdf/fpdf.php';
    
    class GrillePDF extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 8, 'UNIVERSITE EUROMED DE FES', 0, 1, 'C');
            $this->SetFont('Arial', '', 11);
            $this->Cell(0, 6, 'Ecole d\'Ingenierie Digitale et Intelligence Artificielle', 0, 1, 'C');
            $this->Ln(5);
            $this->SetLineWidth(0.5);
            $this->Line(10, $this->GetY(), 200, $this->GetY());
            $this->Ln(8);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
    }
    
    $pdf = new GrillePDF();
    $pdf->AddPage();
    
    // Titre
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'GRILLE D\'EVALUATION', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'PROJET DE FIN D\'ETUDES', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Informations
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, 'INFORMATIONS GENERALES', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Date :', 0, 0);
    $pdf->Cell(0, 6, date('d/m/Y', strtotime($sout['date_soutenance'])), 0, 1);
    $pdf->Cell(50, 6, 'Candidat(s) :', 0, 0);
    $binome_text = $sout['binome_nom'] ? ' & ' . utf8_decode($sout['binome_nom']) : '';
    $pdf->Cell(0, 6, utf8_decode($sout['etudiant_nom']) . $binome_text, 0, 1);
    $pdf->Cell(50, 6, 'Filiere :', 0, 0);
    $pdf->Cell(0, 6, utf8_decode($sout['filiere_nom']), 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Titre du projet :', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, utf8_decode($sout['titre']));
    $pdf->Ln(5);
    
    // Grille d'évaluation
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, 'GRILLE D\'EVALUATION', 0, 1);
    $pdf->Ln(2);
    
    // Critères d'évaluation
    $criteres = [
        ['section' => 'QUALITE DU TRAVAIL REALISE (40 points)', 'items' => [
            ['Pertinence et originalite du sujet', 8],
            ['Complexite technique et qualite de la realisation', 12],
            ['Respect du cahier des charges', 8],
            ['Documentation technique (code, schemas, etc.)', 6],
            ['Tests et validation', 6]
        ]],
        ['section' => 'PRESENTATION ORALE (30 points)', 'items' => [
            ['Clarte et structure de la presentation', 8],
            ['Maitrise du sujet et des concepts', 10],
            ['Qualite des supports visuels (slides, demo)', 6],
            ['Gestion du temps', 6]
        ]],
        ['section' => 'DEFENSE ET QUESTIONS (20 points)', 'items' => [
            ['Comprehension des questions', 6],
            ['Pertinence et precision des reponses', 8],
            ['Capacite d\'argumentation', 6]
        ]],
        ['section' => 'RAPPORT ECRIT (10 points)', 'items' => [
            ['Structure et clarte du rapport', 4],
            ['Qualite redactionnelle', 3],
            ['Bibliographie et references', 3]
        ]]
    ];
    
    $total_max = 0;
    
    foreach ($criteres as $section) {
        // Section
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(130, 7, utf8_decode($section['section']), 1, 0, 'L', true);
        $pdf->Cell(30, 7, 'Points', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Note', 1, 1, 'C', true);
        
        // Items
        $pdf->SetFont('Arial', '', 9);
        foreach ($section['items'] as $item) {
            $pdf->Cell(130, 6, utf8_decode($item[0]), 1, 0, 'L');
            $pdf->Cell(30, 6, '/ ' . $item[1], 1, 0, 'C');
            $pdf->Cell(30, 6, '', 1, 1, 'C'); // Case vide pour la note
            $total_max += $item[1];
        }
        
        $pdf->Ln(3);
    }
    
    // Total
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(130, 8, 'TOTAL', 1, 0, 'R');
    $pdf->Cell(30, 8, '/ 100', 1, 0, 'C');
    $pdf->Cell(30, 8, '', 1, 1, 'C');
    
    $pdf->Ln(5);
    
    // Conversion sur 20
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(130, 8, 'NOTE FINALE (sur 20)', 1, 0, 'R');
    $pdf->Cell(60, 8, '', 1, 1, 'C');
    
    $pdf->Ln(8);
    
    // Appréciation
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'APPRECIATION GENERALE :', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    // Cadre pour appréciation
    $pdf->Rect(10, $pdf->GetY(), 190, 40);
    $pdf->Ln(45);
    
    // Mention
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'MENTION :', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    $mentions = [
        'Passable (10 - 11.99)',
        'Assez Bien (12 - 13.99)',
        'Bien (14 - 15.99)',
        'Tres Bien (16 - 17.99)',
        'Excellent (18 - 20)'
    ];
    
    foreach ($mentions as $mention) {
        $pdf->Cell(10, 6, chr(111), 1, 0, 'C'); // Cercle
        $pdf->Cell(0, 6, ' ' . utf8_decode($mention), 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Signature
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(95, 6, 'Nom et signature de l\'evaluateur :', 0, 0);
    $pdf->Cell(95, 6, 'Date :', 0, 1);
    $pdf->Ln(15);
    $pdf->Cell(95, 6, '', 'B', 0);
    $pdf->Cell(95, 6, date('d/m/Y'), 'B', 1, 'C');
    
    // Enregistrer
    $filename = 'GRILLE_EVAL_' . date('Ymd', strtotime($sout['date_soutenance'])) . '_' . $soutenance_id . '.pdf';
    $filepath = '../uploads/grilles/' . $filename;
    
    if (!is_dir('../uploads/grilles')) {
        mkdir('../uploads/grilles', 0755, true);
    }
    
    $pdf->Output('F', $filepath);
    
    // Télécharger
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($filepath);
    exit;
}

// Liste des soutenances
$where_clause = "WHERE s.date_soutenance >= CURDATE()";
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clause .= " AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
} elseif ($user['role'] === 'professeur') {
    $where_clause .= " AND EXISTS (SELECT 1 FROM jurys j WHERE j.soutenance_id = s.id AND j.professeur_id = ?)";
    $params[] = $user['id'];
}

$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    $where_clause
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute($params);
$soutenances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grilles d'Évaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-clipboard-check"></i> Grilles d'Évaluation</h1>
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
                    Les grilles d'évaluation permettent aux membres du jury de noter objectivement chaque projet 
                    selon des critères précis. Chaque grille est vierge et doit être remplie pendant la soutenance.
                </p>
            </div>
        </div>

        <?php if (count($soutenances) > 0): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-event"></i> 
                        Soutenances à venir (<?= count($soutenances) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Heure</th>
                                    <th>Étudiant</th>
                                    <th>Projet</th>
                                    <th>Filière</th>
                                    <th>Salle</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soutenances as $sout): ?>
                                    <tr>
                                        <td><?= formater_date($sout['date_soutenance'], 'd/m/Y') ?></td>
                                        <td><?= date('H:i', strtotime($sout['heure_debut'])) ?></td>
                                        <td><?= htmlspecialchars($sout['etudiant_nom']) ?></td>
                                        <td><?= htmlspecialchars(substr($sout['titre'], 0, 50)) ?>...</td>
                                        <td><?= htmlspecialchars($sout['filiere_nom']) ?></td>
                                        <td><?= htmlspecialchars($sout['salle_nom']) ?></td>
                                        <td>
                                            <a href="?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-file-earmark-pdf"></i> Générer grille
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
                <i class="bi bi-info-circle"></i> Aucune soutenance prévue.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>