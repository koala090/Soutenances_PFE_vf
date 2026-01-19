<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'assistante']);

$user = get_user_info();
$message = '';
$type_message = '';

// Action : Générer une convocation
if (isset($_GET['soutenance_id']) && isset($_GET['action']) && $_GET['action'] === 'generer') {
    $soutenance_id = intval($_GET['soutenance_id']);
    
    // Récupérer les infos
    $stmt = $pdo->prepare("
        SELECT s.*, p.titre,
               CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
               e.email as etudiant_email,
               f.nom as filiere_nom,
               sa.nom as salle_nom, sa.batiment, sa.etage
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        JOIN utilisateurs e ON p.etudiant_id = e.id
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
        ORDER BY FIELD(j.role_jury, 'president', 'encadrant', 'examinateur')
    ");
    $stmt->execute([$soutenance_id]);
    $jury = $stmt->fetchAll();
    
    require_once '../fpdf/fpdf.php';
    
    // Fonction pour convertir UTF-8 en ISO-8859-1 proprement
    function utf8_to_iso($text) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
    }
    
    class ConvocationPDF extends FPDF {
        function Header() {
            // En-tête avec encodage corrigé
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 8, utf8_to_iso('UNIVERSITÉ EUROMED DE FÈS'), 0, 1, 'C');
            $this->SetFont('Arial', '', 11);
            $this->Cell(0, 6, utf8_to_iso('École d\'Ingénierie Digitale et Intelligence Artificielle'), 0, 1, 'C');
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
    
    $pdf = new ConvocationPDF();
    $pdf->AddPage();
    
    // Titre
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'CONVOCATION A SOUTENANCE', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, utf8_to_iso('PROJET DE FIN D\'ÉTUDES'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Informations
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 7, 'Date :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, date('d/m/Y', strtotime($sout['date_soutenance'])), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 7, 'Heure :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, date('H:i', strtotime($sout['heure_debut'])), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 7, 'Salle :', 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $salle_info = $sout['salle_nom'];
    if ($sout['batiment']) $salle_info .= ' - ' . $sout['batiment'];
    if ($sout['etage']) $salle_info .= ' (Etage ' . $sout['etage'] . ')';
    $pdf->Cell(0, 7, utf8_to_iso($salle_info), 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 7, utf8_to_iso('Filière :'), 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, utf8_to_iso($sout['filiere_nom']), 0, 1);
    $pdf->Ln(5);
    
    // Candidat
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'CANDIDAT', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, utf8_to_iso($sout['etudiant_nom']));
    $pdf->Ln(3);
    
    // Projet
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'PROJET', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, utf8_to_iso($sout['titre']));
    $pdf->Ln(3);
    
    // Composition du jury
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'COMPOSITION DU JURY', 0, 1);
    $pdf->Ln(2);
    
    $roles_fr = [
        'president' => 'Président du jury',
        'encadrant' => 'Encadrant',
        'examinateur' => 'Examinateur',
        'rapporteur' => 'Rapporteur'
    ];
    
    foreach ($jury as $membre) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 6, utf8_to_iso($roles_fr[$membre['role_jury']]) . ' :', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, utf8_to_iso($membre['prenom'] . ' ' . $membre['nom']), 0, 1);
    }
    $pdf->Ln(10);
    
    // Note importante
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->MultiCell(0, 6, utf8_to_iso("Merci de confirmer votre présence et de vous présenter 10 minutes avant l'heure indiquée."), 1, 'L', true);
    $pdf->Ln(15);
    
    // Signature
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, utf8_to_iso('Le Coordinateur de filière'), 0, 1, 'R');
    
    // Créer le dossier si nécessaire
    $dir = '../uploads/convocations/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Nom du fichier
    $filename = 'CONVOCATION_' . date('Ymd', strtotime($sout['date_soutenance'])) . '_' . $soutenance_id . '.pdf';
    $filepath = $dir . $filename;
    
    // Nettoyer tout output buffer avant
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Générer le PDF
    try {
        // Sauvegarder d'abord
        $pdf->Output('F', $filepath);
        
        // Puis envoyer au navigateur
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        readfile($filepath);
        exit;
    } catch (Exception $e) {
        die("Erreur lors de la génération du PDF : " . $e->getMessage());
    }
}

// Action : Marquer convocations comme envoyées
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'marquer_envoyees') {
    $soutenance_id = intval($_POST['soutenance_id']);
    
    $stmt = $pdo->prepare("
        UPDATE jurys SET convocation_envoyee = 1, date_convocation = NOW() 
        WHERE soutenance_id = ?
    ");
    $stmt->execute([$soutenance_id]);
    
    $message = "Convocations marquées comme envoyées";
    $type_message = 'success';
}

// Récupérer les soutenances à venir
$where_clause = "";
$params = [date('Y-m-d')];

if ($user['role'] === 'coordinateur') {
    $where_clause = "AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom,
           COUNT(j.id) as nb_jury,
           SUM(CASE WHEN j.convocation_envoyee = 1 THEN 1 ELSE 0 END) as nb_convocs_envoyees
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    WHERE s.date_soutenance >= ? AND s.statut IN ('planifiee', 'confirmee') $where_clause
    GROUP BY s.id
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
    <title>Génération des Convocations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-send"></i> Convocations de Soutenance</h1>
            <a href="../dashboards/<?= $user['role'] ?>.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $type_message === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="bi bi-info-circle"></i> Information
            </div>
            <div class="card-body">
                <p class="mb-0">
                    Les convocations sont générées automatiquement pour les étudiants et les membres du jury.
                    Une fois générées, vous pouvez les télécharger et les marquer comme envoyées.
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
                                    <th>Salle</th>
                                    <th>Jury</th>
                                    <th>Convocations</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soutenances as $sout): ?>
                                    <tr>
                                        <td><?= formater_date($sout['date_soutenance'], 'd/m/Y') ?></td>
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
                                            <?php if ($sout['nb_convocs_envoyees'] > 0): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i>
                                                    <?= $sout['nb_convocs_envoyees'] ?>/<?= $sout['nb_jury'] ?> envoyées
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non envoyées</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                                   class="btn btn-primary" 
                                                   title="Générer PDF"
                                                   target="_blank">
                                                    <i class="bi bi-file-earmark-pdf"></i> Générer
                                                </a>
                                                <?php if ($sout['nb_convocs_envoyees'] < $sout['nb_jury']): ?>
                                                    <button type="button" 
                                                            class="btn btn-success" 
                                                            onclick="marquerEnvoyees(<?= $sout['id'] ?>)"
                                                            title="Marquer comme envoyées">
                                                        <i class="bi bi-send-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
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
                <i class="bi bi-info-circle"></i> Aucune soutenance planifiée pour le moment.
            </div>
        <?php endif; ?>
    </div>

    <form id="formMarquer" method="POST" style="display: none;">
        <input type="hidden" name="action" value="marquer_envoyees">
        <input type="hidden" name="soutenance_id" id="soutenance_id_input">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function marquerEnvoyees(soutenanceId) {
            if (confirm('Marquer les convocations comme envoyées ?')) {
                document.getElementById('soutenance_id_input').value = soutenanceId;
                document.getElementById('formMarquer').submit();
            }
        }
    </script>
</body>
</html>