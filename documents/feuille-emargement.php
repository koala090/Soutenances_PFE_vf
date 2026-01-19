<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'assistante']);

$user = get_user_info();

// Action : Générer la feuille d'émargement
if (isset($_GET['soutenance_id']) && isset($_GET['action']) && $_GET['action'] === 'generer') {
    $soutenance_id = intval($_GET['soutenance_id']);
    
    // Récupérer les infos
    $stmt = $pdo->prepare("
        SELECT s.*, p.titre,
               CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
               CONCAT(b.prenom, ' ', b.nom) as binome_nom,
               f.nom as filiere_nom,
               sa.nom as salle_nom, sa.batiment
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN utilisateurs b ON p.binome_id = b.id
        JOIN filieres f ON p.filiere_id = f.id
        JOIN salles sa ON s.salle_id = sa.id
        WHERE s.id = ?
    ");
    $stmt->execute([$soutenance_id]);
    $sout = $stmt->fetch();
    
    if (!$sout) {
        die("Soutenance introuvable");
    }
    
    // Récupérer les membres du jury
    $stmt = $pdo->prepare("
        SELECT j.*, u.nom, u.prenom, u.email
        FROM jurys j
        JOIN utilisateurs u ON j.professeur_id = u.id
        WHERE j.soutenance_id = ?
        ORDER BY FIELD(j.role_jury, 'president', 'encadrant', 'examinateur', 'rapporteur')
    ");
    $stmt->execute([$soutenance_id]);
    $jury = $stmt->fetchAll();
    
    require_once '../libs/fpdf/fpdf.php';
    
    class EmargementPDF extends FPDF {
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
    
    $pdf = new EmargementPDF();
    $pdf->AddPage();
    
    // Titre
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'FEUILLE D\'EMARGEMENT', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'JURY DE SOUTENANCE - PROJET DE FIN D\'ETUDES', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Informations soutenance
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, 'INFORMATIONS SOUTENANCE', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->Cell(50, 6, 'Date :', 0, 0);
    $pdf->Cell(0, 6, date('d/m/Y', strtotime($sout['date_soutenance'])), 0, 1);
    
    $pdf->Cell(50, 6, 'Heure :', 0, 0);
    $pdf->Cell(0, 6, date('H:i', strtotime($sout['heure_debut'])) . ' - ' . date('H:i', strtotime($sout['heure_fin'])), 0, 1);
    
    $pdf->Cell(50, 6, 'Salle :', 0, 0);
    $salle_info = utf8_decode($sout['salle_nom']);
    if ($sout['batiment']) $salle_info .= ' - ' . utf8_decode($sout['batiment']);
    $pdf->Cell(0, 6, $salle_info, 0, 1);
    
    $pdf->Cell(50, 6, 'Filiere :', 0, 0);
    $pdf->Cell(0, 6, utf8_decode($sout['filiere_nom']), 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(50, 6, 'Candidat(s) :', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $candidats = utf8_decode($sout['etudiant_nom']);
    if ($sout['binome_nom']) $candidats .= ' & ' . utf8_decode($sout['binome_nom']);
    $pdf->Cell(0, 6, $candidats, 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Projet :', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, utf8_decode($sout['titre']));
    $pdf->Ln(8);
    
    // Tableau d'émargement
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'LISTE DES MEMBRES DU JURY', 0, 1);
    $pdf->Ln(3);
    
    // En-tête du tableau
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 10);
    
    $pdf->Cell(10, 10, 'N°', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Role', 1, 0, 'C', true);
    $pdf->Cell(50, 10, 'Nom et Prenom', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Heure arrivee', 1, 0, 'C', true);
    $pdf->Cell(65, 10, 'Signature', 1, 1, 'C', true);
    
    // Lignes des membres du jury
    $pdf->SetFont('Arial', '', 10);
    $roles_fr = [
        'president' => 'President',
        'encadrant' => 'Encadrant',
        'examinateur' => 'Examinateur',
        'rapporteur' => 'Rapporteur',
        'invite' => 'Invite'
    ];
    
    $num = 1;
    foreach ($jury as $membre) {
        $pdf->Cell(10, 12, $num, 1, 0, 'C');
        $pdf->Cell(40, 12, $roles_fr[$membre['role_jury']], 1, 0, 'L');
        $pdf->Cell(50, 12, utf8_decode($membre['prenom'] . ' ' . $membre['nom']), 1, 0, 'L');
        $pdf->Cell(25, 12, '', 1, 0, 'C'); // Heure à remplir
        $pdf->Cell(65, 12, '', 1, 1, 'C'); // Signature
        $num++;
    }
    
    $pdf->Ln(10);
    
    // Section observations
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, 'OBSERVATIONS / INCIDENTS', 0, 1);
    $pdf->Ln(2);
    
    // Cadre pour observations
    $pdf->SetFont('Arial', '', 9);
    $pdf->Rect(10, $pdf->GetY(), 190, 40);
    $pdf->Ln(45);
    
    // Validation
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, 'VALIDATION DE LA FEUILLE D\'EMARGEMENT', 0, 1);
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 10);
    
    // Président du jury
    $pdf->Cell(95, 6, 'Le President du jury :', 0, 0);
    $pdf->Cell(95, 6, 'Date et heure de cloture :', 0, 1);
    $pdf->Ln(2);
    
    $president = null;
    foreach ($jury as $m) {
        if ($m['role_jury'] === 'president') {
            $president = $m;
            break;
        }
    }
    
    if ($president) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(95, 5, utf8_decode($president['prenom'] . ' ' . $president['nom']), 0, 0);
    } else {
        $pdf->Cell(95, 5, '', 0, 0);
    }
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(95, 5, date('d/m/Y') . ' a ____:____', 0, 1);
    
    $pdf->Ln(10);
    $pdf->Cell(95, 6, '', 'B', 0); // Ligne signature
    $pdf->Cell(95, 6, '', 'B', 1);
    
    $pdf->Ln(10);
    
    // Note importante
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetFillColor(250, 250, 200);
    $pdf->MultiCell(0, 5, utf8_decode("NOTE : Cette feuille d'émargement doit être signée par tous les membres du jury présents. " .
                                       "Elle atteste de la composition effective du jury et de la régularité de la soutenance. " .
                                       "Elle doit être conservée avec le procès-verbal de soutenance."), 
                    1, 'L', true);
    
    // Enregistrer
    $filename = 'EMARGEMENT_' . date('Ymd', strtotime($sout['date_soutenance'])) . '_' . $soutenance_id . '.pdf';
    $filepath = '../uploads/emargements/' . $filename;
    
    if (!is_dir('../uploads/emargements')) {
        mkdir('../uploads/emargements', 0755, true);
    }
    
    $pdf->Output('F', $filepath);
    
    // Télécharger
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($filepath);
    exit;
}

// Action : Générer pour une date
if (isset($_GET['date']) && isset($_GET['action']) && $_GET['action'] === 'generer_jour') {
    $date = $_GET['date'];
    
    // Récupérer toutes les soutenances du jour
    $where_clause = "WHERE DATE(s.date_soutenance) = ?";
    $params = [$date];
    
    if ($user['role'] === 'coordinateur') {
        $where_clause .= " AND p.filiere_id = ?";
        $params[] = $user['filiere_id'];
    }
    
    $stmt = $pdo->prepare("
        SELECT s.id
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        $where_clause
        ORDER BY s.heure_debut
    ");
    $stmt->execute($params);
    $soutenances_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($soutenances_ids) === 0) {
        die("Aucune soutenance ce jour");
    }
    
    // Générer un PDF groupé (version simplifiée - redirection vers chaque PDF)
    // Pour une version plus avancée, on pourrait tout combiner en un seul PDF
    header("Location: ?info=multiple&nb=" . count($soutenances_ids));
    exit;
}

// Liste des soutenances
$where_clause = "WHERE s.date_soutenance >= CURDATE()";
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clause .= " AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom,
           COUNT(j.id) as nb_jury
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    $where_clause
    GROUP BY s.id
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute($params);
$soutenances = $stmt->fetchAll();

// Grouper par date
$soutenances_par_date = [];
foreach ($soutenances as $s) {
    $date = $s['date_soutenance'];
    if (!isset($soutenances_par_date[$date])) {
        $soutenances_par_date[$date] = [];
    }
    $soutenances_par_date[$date][] = $s;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feuilles d'Émargement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil-square"></i> Feuilles d'Émargement</h1>
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
                    Les feuilles d'émargement permettent d'attester de la présence effective de chaque membre du jury.
                    Elles doivent être signées par tous les participants et conservées avec le procès-verbal.
                </p>
            </div>
        </div>

        <?php if (count($soutenances_par_date) > 0): ?>
            <?php foreach ($soutenances_par_date as $date => $soutenances_jour): ?>
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-calendar-event"></i> 
                                <?= formater_date($date, 'd F Y') ?> (<?= count($soutenances_jour) ?> soutenance(s))
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Heure</th>
                                        <th>Étudiant</th>
                                        <th>Projet</th>
                                        <th>Salle</th>
                                        <th>Jury</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($soutenances_jour as $sout): ?>
                                        <tr>
                                            <td><?= date('H:i', strtotime($sout['heure_debut'])) ?></td>
                                            <td><?= htmlspecialchars($sout['etudiant_nom']) ?></td>
                                            <td><?= htmlspecialchars(substr($sout['titre'], 0, 40)) ?>...</td>
                                            <td><?= htmlspecialchars($sout['salle_nom']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $sout['nb_jury'] >= 3 ? 'success' : 'warning' ?>">
                                                    <?= $sout['nb_jury'] ?> membres
                                                </span>
                                            </td>
                                            <td>
                                                <a href="?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="bi bi-file-earmark-pdf"></i> Générer
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Aucune soutenance prévue.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>