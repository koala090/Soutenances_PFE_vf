<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'directeur', 'assistante']);

// Action : Générer le PV
if (isset($_GET['soutenance_id']) && isset($_GET['action']) && $_GET['action'] === 'generer') {
    $soutenance_id = intval($_GET['soutenance_id']);
    
    // Récupérer toutes les infos de la soutenance
    $stmt = $pdo->prepare("
        SELECT s.*, p.titre, p.description,
               CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
               e.email as etudiant_email,
               CONCAT(b.prenom, ' ', b.nom) as binome_nom,
               f.nom as filiere_nom, f.code as filiere_code,
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
        SELECT j.*, u.nom, u.prenom, u.email, j.role_jury, j.note_attribuee
        FROM jurys j
        JOIN utilisateurs u ON j.professeur_id = u.id
        WHERE j.soutenance_id = ?
        ORDER BY FIELD(j.role_jury, 'president', 'encadrant', 'examinateur', 'rapporteur')
    ");
    $stmt->execute([$soutenance_id]);
    $jury = $stmt->fetchAll();
    
    // Générer le PDF avec FPDF
    require_once '../libs/fpdf/fpdf.php';
    
    class PDF extends FPDF {
        private $filiere = '';
        
        function SetFiliere($filiere) {
            $this->filiere = $filiere;
        }
        
        function Header() {
            // Logo ou En-tête
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, 'UNIVERSITE EUROMED DE FES', 0, 1, 'C');
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 8, 'ECOLE D\'INGENIERIE DIGITALE ET INTELLIGENCE ARTIFICIELLE', 0, 1, 'C');
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 6, $this->filiere, 0, 1, 'C');
            $this->Ln(5);
            
            // Ligne de séparation
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
    
    $pdf = new PDF();
    $pdf->SetFiliere($sout['filiere_nom']);
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 11);
    
    // Titre
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'PROCES-VERBAL DE SOUTENANCE', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, 'PROJET DE FIN D\'ETUDES', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Informations générales
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'I. INFORMATIONS GENERALES', 0, 1, 'L');
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 6, 'Date de soutenance :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, date('d/m/Y', strtotime($sout['date_soutenance'])), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 6, 'Heure :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, date('H:i', strtotime($sout['heure_debut'])) . ' - ' . date('H:i', strtotime($sout['heure_fin'])), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 6, 'Lieu :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, utf8_decode($sout['salle_nom'] . ' - ' . $sout['batiment']), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 6, 'Filiere :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, utf8_decode($sout['filiere_nom']), 0, 1);
    $pdf->Ln(5);
    
    // Candidat(s)
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'II. CANDIDAT(S)', 0, 1, 'L');
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 6, 'Etudiant :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, utf8_decode($sout['etudiant_nom']), 0, 1);
    
    if ($sout['binome_nom']) {
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 6, 'Binome :', 0, 0);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, utf8_decode($sout['binome_nom']), 0, 1);
    }
    $pdf->Ln(5);
    
    // Projet
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'III. PROJET', 0, 1, 'L');
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, 'Titre :', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, utf8_decode($sout['titre']));
    $pdf->Ln(3);
    
    // Jury
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'IV. COMPOSITION DU JURY', 0, 1, 'L');
    $pdf->Ln(2);
    
    foreach ($jury as $membre) {
        $role_fr = [
            'president' => 'President',
            'encadrant' => 'Encadrant',
            'examinateur' => 'Examinateur',
            'rapporteur' => 'Rapporteur'
        ];
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(40, 6, $role_fr[$membre['role_jury']] . ' :', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, utf8_decode($membre['prenom'] . ' ' . $membre['nom']), 0, 1);
    }
    $pdf->Ln(5);
    
    // Résultats
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'V. RESULTATS', 0, 1, 'L');
    $pdf->Ln(2);
    
    if ($sout['note_finale']) {
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 6, 'Note finale :', 0, 0);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, number_format($sout['note_finale'], 2) . ' / 20', 0, 1);
        
        if ($sout['mention']) {
            $mentions = [
                'passable' => 'Passable',
                'assez_bien' => 'Assez Bien',
                'bien' => 'Bien',
                'tres_bien' => 'Tres Bien',
                'excellent' => 'Excellent'
            ];
            
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(50, 6, 'Mention :', 0, 0);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 6, $mentions[$sout['mention']], 0, 1);
        }
    } else {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 6, 'Note non saisie', 0, 1);
    }
    $pdf->Ln(3);
    
    // Observations
    if ($sout['observations']) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'Observations du jury :', 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 6, utf8_decode($sout['observations']));
        $pdf->Ln(3);
    }
    
    // Signatures
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'VI. SIGNATURES', 0, 1, 'L');
    $pdf->Ln(5);
    
    $x_start = 10;
    $col_width = 60;
    $y_pos = $pdf->GetY();
    
    foreach ($jury as $index => $membre) {
        if ($index > 0 && $index % 3 === 0) {
            $y_pos += 25;
            $pdf->SetY($y_pos);
        }
        
        $x_pos = $x_start + ($index % 3) * $col_width;
        $pdf->SetXY($x_pos, $y_pos);
        
        $role_fr = [
            'president' => 'President',
            'encadrant' => 'Encadrant',
            'examinateur' => 'Examinateur',
            'rapporteur' => 'Rapporteur'
        ];
        
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell($col_width - 5, 5, $role_fr[$membre['role_jury']], 0, 1);
        $pdf->SetX($x_pos);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($col_width - 5, 5, utf8_decode(substr($membre['prenom'] . ' ' . $membre['nom'], 0, 20)), 0, 1);
    }
    
    // Enregistrer le fichier
    $filename = 'PV_' . date('Ymd', strtotime($sout['date_soutenance'])) . '_' . $soutenance_id . '.pdf';
    $filepath = '../uploads/pv/' . $filename;
    
    // Créer le dossier si nécessaire
    if (!is_dir('../uploads/pv')) {
        mkdir('../uploads/pv', 0755, true);
    }
    
    $pdf->Output('F', $filepath);
    
    // Mettre à jour la BDD
    $stmt = $pdo->prepare("UPDATE soutenances SET pv_genere = 1, chemin_pv = ? WHERE id = ?");
    $stmt->execute([$filepath, $soutenance_id]);
    
    // Télécharger le PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($filepath);
    exit;
}

$user = get_user_info();

// Récupérer les soutenances terminées
$where_clause = "";
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clause = "AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
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
    WHERE s.statut = 'terminee' $where_clause
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
    <title>Génération des PV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-earmark-pdf"></i> Génération des Procès-Verbaux</h1>
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
                    Cette page permet de générer automatiquement les procès-verbaux (PV) des soutenances terminées.
                    Le PV contient : informations générales, candidats, projet, composition du jury, résultats et signatures.
                </p>
            </div>
        </div>

        <?php if (count($soutenances) > 0): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-list-check"></i> Soutenances terminées (<?= count($soutenances) ?>)</h5>
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
                                    <th>PV</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soutenances as $sout): ?>
                                    <tr>
                                        <td><?= formater_date($sout['date_soutenance'], 'd/m/Y') ?></td>
                                        <td><?= htmlspecialchars($sout['etudiant_nom']) ?></td>
                                        <td><?= htmlspecialchars(substr($sout['titre'], 0, 50)) ?>...</td>
                                        <td><?= htmlspecialchars($sout['filiere_nom']) ?></td>
                                        <td>
                                            <?php if ($sout['note_finale']): ?>
                                                <span class="badge bg-success">
                                                    <?= number_format($sout['note_finale'], 2) ?>/20
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Non saisie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sout['pv_genere']): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Généré
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non généré</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                                <?= $sout['pv_genere'] ? 'Régénérer' : 'Générer' ?> PV
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
                <i class="bi bi-info-circle"></i> Aucune soutenance terminée pour le moment.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>