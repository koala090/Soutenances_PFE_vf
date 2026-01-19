<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['assistante', 'coordinateur', 'directeur']);

$user = get_user_info();
$message = '';
$type_message = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'marquer_archive') {
            $soutenance_id = intval($_POST['soutenance_id']);
            
            // Créer l'enregistrement d'archivage
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS archives (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    soutenance_id INT NOT NULL,
                    archiviste_id INT NOT NULL,
                    date_archivage TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    emplacement VARCHAR(255),
                    notes TEXT,
                    FOREIGN KEY (soutenance_id) REFERENCES soutenances(id),
                    FOREIGN KEY (archiviste_id) REFERENCES utilisateurs(id)
                )
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO archives (soutenance_id, archiviste_id, emplacement, notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $soutenance_id,
                $user['id'],
                $_POST['emplacement'] ?? '',
                $_POST['notes'] ?? ''
            ]);
            
            $message = "Document archivé avec succès";
            $type_message = 'success';
            
        } elseif ($action === 'generer_bordereau') {
            $annee = $_POST['annee'] ?? date('Y');
            $filiere_id = $_POST['filiere_id'] ?? null;
            
            // Générer un bordereau d'archivage
            require_once '../libs/fpdf/fpdf.php';
            
            $where = "WHERE YEAR(s.date_soutenance) = ?";
            $params = [$annee];
            
            if ($filiere_id) {
                $where .= " AND p.filiere_id = ?";
                $params[] = $filiere_id;
            }
            
            $stmt = $pdo->prepare("
                SELECT s.*, p.titre,
                       CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
                       f.nom as filiere_nom
                FROM soutenances s
                JOIN projets p ON s.projet_id = p.id
                JOIN utilisateurs e ON p.etudiant_id = e.id
                JOIN filieres f ON p.filiere_id = f.id
                $where
                AND s.statut = 'terminee'
                AND s.pv_genere = 1
                ORDER BY s.date_soutenance
            ");
            $stmt->execute($params);
            $soutenances = $stmt->fetchAll();
            
            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'BORDEREAU D\'ARCHIVAGE', 0, 1, 'C');
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 8, 'Annee : ' . $annee, 0, 1);
            $pdf->Ln(5);
            
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, 'Date', 1, 0, 'C');
            $pdf->Cell(60, 7, 'Etudiant', 1, 0, 'C');
            $pdf->Cell(50, 7, 'Filiere', 1, 0, 'C');
            $pdf->Cell(50, 7, 'Documents', 1, 1, 'C');
            
            $pdf->SetFont('Arial', '', 9);
            foreach ($soutenances as $s) {
                $pdf->Cell(30, 6, date('d/m/Y', strtotime($s['date_soutenance'])), 1, 0);
                $pdf->Cell(60, 6, substr($s['etudiant_nom'], 0, 25), 1, 0);
                $pdf->Cell(50, 6, substr($s['filiere_nom'], 0, 20), 1, 0);
                $pdf->Cell(50, 6, 'PV + Rapport', 1, 1);
            }
            
            $filename = 'BORDEREAU_ARCHIVAGE_' . $annee . '.pdf';
            $filepath = '../uploads/bordereaux/' . $filename;
            
            if (!is_dir('../uploads/bordereaux')) {
                mkdir('../uploads/bordereaux', 0755, true);
            }
            
            $pdf->Output('F', $filepath);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            readfile($filepath);
            exit;
        }
        
    } catch (Exception $e) {
        $message = "Erreur : " . $e->getMessage();
        $type_message = 'error';
    }
}

// Vérifier si la table archives existe
$pdo->exec("
    CREATE TABLE IF NOT EXISTS archives (
        id INT PRIMARY KEY AUTO_INCREMENT,
        soutenance_id INT NOT NULL,
        archiviste_id INT NOT NULL,
        date_archivage TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        emplacement VARCHAR(255),
        notes TEXT,
        FOREIGN KEY (soutenance_id) REFERENCES soutenances(id),
        FOREIGN KEY (archiviste_id) REFERENCES utilisateurs(id),
        INDEX(soutenance_id)
    )
");

// Récupérer les PV à archiver
$where_clause = "WHERE s.statut = 'terminee' AND s.pv_genere = 1";
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clause .= " AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom,
           a.id as archive_id,
           a.date_archivage,
           a.emplacement
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    LEFT JOIN archives a ON s.id = a.soutenance_id
    $where_clause
    ORDER BY s.date_soutenance DESC
");
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Séparer archivés et non archivés
$non_archives = [];
$archives = [];

foreach ($documents as $doc) {
    if ($doc['archive_id']) {
        $archives[] = $doc;
    } else {
        $non_archives[] = $doc;
    }
}

// Statistiques
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_archives,
        COUNT(DISTINCT YEAR(date_archivage)) as annees
    FROM archives
");
$stats = $stmt->fetch();

// Années disponibles pour les filtres
$stmt = $pdo->query("
    SELECT DISTINCT YEAR(s.date_soutenance) as annee
    FROM soutenances s
    WHERE s.statut = 'terminee'
    ORDER BY annee DESC
");
$annees = $stmt->fetchAll();

// Filières
$stmt = $pdo->query("SELECT id, nom FROM filieres ORDER BY nom");
$filieres = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivage des Documents</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .archive-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .archive-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .status-archived {
            background: #d1fae5;
            color: #065f46;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-archive"></i> Archivage des Documents</h1>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bordereauModal">
                    <i class="bi bi-file-earmark-text"></i> Générer bordereau
                </button>
                <a href="../dashboards/<?= $user['role'] ?>.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $type_message === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?= count($archives) ?></h3>
                        <p class="text-muted mb-0">Documents archivés</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning"><?= count($non_archives) ?></h3>
                        <p class="text-muted mb-0">En attente d'archivage</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info"><?= $stats['total_archives'] ?? 0 ?></h3>
                        <p class="text-muted mb-0">Total général</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documents à archiver -->
        <?php if (count($non_archives) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        Documents à archiver (<?= count($non_archives) ?>)
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
                                    <th>PV</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($non_archives as $doc): ?>
                                    <tr>
                                        <td><?= formater_date($doc['date_soutenance'], 'd/m/Y') ?></td>
                                        <td><?= htmlspecialchars($doc['etudiant_nom']) ?></td>
                                        <td>
                                            <small><?= htmlspecialchars(substr($doc['titre'], 0, 40)) ?>...</small>
                                        </td>
                                        <td><?= htmlspecialchars($doc['filiere_nom']) ?></td>
                                        <td>
                                            <?php if ($doc['note_finale']): ?>
                                                <span class="badge bg-success">
                                                    <?= number_format($doc['note_finale'], 2) ?>/20
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($doc['pv_signe']): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Signé
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">En attente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#archiveModal"
                                                    data-soutenance-id="<?= $doc['id'] ?>"
                                                    data-etudiant="<?= htmlspecialchars($doc['etudiant_nom']) ?>">
                                                <i class="bi bi-archive"></i> Archiver
                                            </button>
                                            <?php if ($doc['chemin_pv']): ?>
                                                <a href="<?= htmlspecialchars($doc['chemin_pv']) ?>" 
                                                   class="btn btn-sm btn-outline-secondary"
                                                   target="_blank">
                                                    <i class="bi bi-file-pdf"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents archivés -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-check-circle"></i>
                    Documents archivés (<?= count($archives) ?>)
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($archives) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date soutenance</th>
                                    <th>Étudiant</th>
                                    <th>Filière</th>
                                    <th>Date archivage</th>
                                    <th>Emplacement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archives as $arc): ?>
                                    <tr>
                                        <td><?= formater_date($arc['date_soutenance'], 'd/m/Y') ?></td>
                                        <td><?= htmlspecialchars($arc['etudiant_nom']) ?></td>
                                        <td><?= htmlspecialchars($arc['filiere_nom']) ?></td>
                                        <td><?= formater_date($arc['date_archivage'], 'd/m/Y H:i') ?></td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($arc['emplacement'] ?: 'Non spécifié') ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted">Aucun document archivé pour le moment</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Archivage -->
    <div class="modal fade" id="archiveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="marquer_archive">
                    <input type="hidden" name="soutenance_id" id="modal_soutenance_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-archive"></i> Archiver le document
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Étudiant :</strong> <span id="modal_etudiant_nom"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Emplacement physique *</label>
                            <input type="text" 
                                   name="emplacement" 
                                   class="form-control" 
                                   placeholder="Ex: Armoire A3, Étagère 2, Boîte 2024"
                                   required>
                            <small class="text-muted">Indiquez où le document est rangé</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" 
                                      class="form-control" 
                                      rows="3"
                                      placeholder="Remarques éventuelles..."></textarea>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="confirm_archive" required>
                            <label class="form-check-label" for="confirm_archive">
                                Je confirme avoir archivé physiquement ce document
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Marquer comme archivé
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Bordereau -->
    <div class="modal fade" id="bordereauModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="generer_bordereau">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-file-earmark-text"></i> Générer un bordereau d'archivage
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Le bordereau liste tous les documents archivés pour une année donnée
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Année universitaire *</label>
                            <select name="annee" class="form-select" required>
                                <?php foreach ($annees as $a): ?>
                                    <option value="<?= $a['annee'] ?>" <?= $a['annee'] == date('Y') ? 'selected' : '' ?>>
                                        <?= $a['annee'] ?> - <?= ($a['annee'] + 1) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Filière (optionnel)</label>
                            <select name="filiere_id" class="form-select">
                                <option value="">Toutes les filières</option>
                                <?php foreach ($filieres as $f): ?>
                                    <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-download"></i> Générer le bordereau
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Remplir le modal d'archivage avec les données
        const archiveModal = document.getElementById('archiveModal');
        archiveModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const soutenanceId = button.getAttribute('data-soutenance-id');
            const etudiantNom = button.getAttribute('data-etudiant');
            
            document.getElementById('modal_soutenance_id').value = soutenanceId;
            document.getElementById('modal_etudiant_nom').textContent = etudiantNom;
        });
    </script>
</body>
</html>