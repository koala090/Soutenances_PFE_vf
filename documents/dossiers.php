<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['assistante', 'coordinateur']);

$user = get_user_info();
$message = '';
$type_message = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'marquer_prepare') {
            // Créer la table si elle n'existe pas
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dossiers_prepares (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    soutenance_id INT NOT NULL UNIQUE,
                    prepare_par INT NOT NULL,
                    date_preparation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    documents_imprimes BOOLEAN DEFAULT TRUE,
                    materiel_prepare BOOLEAN DEFAULT TRUE,
                    notes TEXT,
                    FOREIGN KEY (soutenance_id) REFERENCES soutenances(id),
                    FOREIGN KEY (prepare_par) REFERENCES utilisateurs(id)
                )
            ");
            
            $soutenance_id = intval($_POST['soutenance_id']);
            
            // Vérifier si déjà préparé
            $stmt = $pdo->prepare("SELECT id FROM dossiers_prepares WHERE soutenance_id = ?");
            $stmt->execute([$soutenance_id]);
            
            if ($stmt->fetch()) {
                $message = "Ce dossier a déjà été marqué comme préparé";
                $type_message = 'warning';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dossiers_prepares 
                    (soutenance_id, prepare_par, documents_imprimes, materiel_prepare, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $soutenance_id,
                    $user['id'],
                    isset($_POST['documents_imprimes']) ? 1 : 0,
                    isset($_POST['materiel_prepare']) ? 1 : 0,
                    $_POST['notes'] ?? ''
                ]);
                
                $message = "Dossier marqué comme préparé avec succès";
                $type_message = 'success';
            }
            
        } elseif ($action === 'generer_liste_impression') {
            $date_debut = $_POST['date_debut'] ?? date('Y-m-d');
            $date_fin = $_POST['date_fin'] ?? date('Y-m-d', strtotime('+7 days'));
            
            // Récupérer les soutenances pour la période
            $stmt = $pdo->prepare("
                SELECT s.*, p.titre, p.description,
                       CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
                       CONCAT(b.prenom, ' ', b.nom) as binome_nom,
                       CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
                       f.nom as filiere_nom,
                       sa.nom as salle_nom
                FROM soutenances s
                JOIN projets p ON s.projet_id = p.id
                JOIN utilisateurs e ON p.etudiant_id = e.id
                LEFT JOIN utilisateurs b ON p.binome_id = b.id
                LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
                JOIN filieres f ON p.filiere_id = f.id
                JOIN salles sa ON s.salle_id = sa.id
                WHERE s.date_soutenance BETWEEN ? AND ?
                AND s.statut IN ('planifiee', 'confirmee')
                ORDER BY s.date_soutenance, s.heure_debut
            ");
            $stmt->execute([$date_debut, $date_fin]);
            $soutenances = $stmt->fetchAll();
            
            // Générer un document HTML pour impression
            header('Content-Type: text/html; charset=utf-8');
            ?>
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <title>Liste d'impression - Dossiers de soutenance</title>
                <style>
                    @media print {
                        .no-print { display: none; }
                        @page { margin: 2cm; }
                    }
                    body { font-family: Arial, sans-serif; font-size: 12pt; }
                    h1 { text-align: center; border-bottom: 3px solid #000; padding-bottom: 10px; }
                    .soutenance { page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #ccc; padding: 15px; }
                    .header { background: #f0f0f0; padding: 10px; margin-bottom: 10px; }
                    .checklist { margin-top: 15px; }
                    .checklist div { margin: 5px 0; }
                    input[type="checkbox"] { margin-right: 10px; }
                    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                    table td { padding: 5px; }
                    .label { font-weight: bold; width: 150px; }
                </style>
            </head>
            <body>
                <div class="no-print" style="text-align: center; margin: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; font-size: 14pt;">
                        🖨️ Imprimer
                    </button>
                    <button onclick="window.close()" style="padding: 10px 20px; font-size: 14pt; margin-left: 10px;">
                        ✖️ Fermer
                    </button>
                </div>
                
                <h1>📋 LISTE DES DOSSIERS À PRÉPARER</h1>
                <p style="text-align: center;">
                    Période : <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?><br>
                    Total : <?= count($soutenances) ?> soutenance(s)
                </p>
                
                <?php foreach ($soutenances as $s): ?>
                <div class="soutenance">
                    <div class="header">
                        <strong>🗓️ <?= date('d/m/Y', strtotime($s['date_soutenance'])) ?> à <?= date('H:i', strtotime($s['heure_debut'])) ?></strong>
                        - Salle <?= htmlspecialchars($s['salle_nom']) ?>
                    </div>
                    
                    <table>
                        <tr>
                            <td class="label">Étudiant(s) :</td>
                            <td>
                                <?= htmlspecialchars($s['etudiant_nom']) ?>
                                <?php if ($s['binome_nom']): ?>
                                    & <?= htmlspecialchars($s['binome_nom']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label">Projet :</td>
                            <td><?= htmlspecialchars($s['titre']) ?></td>
                        </tr>
                        <tr>
                            <td class="label">Encadrant :</td>
                            <td><?= htmlspecialchars($s['encadrant_nom'] ?? 'Non affecté') ?></td>
                        </tr>
                        <tr>
                            <td class="label">Filière :</td>
                            <td><?= htmlspecialchars($s['filiere_nom']) ?></td>
                        </tr>
                    </table>
                    
                    <div class="checklist">
                        <strong>✓ CHECKLIST DE PRÉPARATION :</strong>
                        <div><input type="checkbox"> Fiche étudiant imprimée</div>
                        <div><input type="checkbox"> Grille d'évaluation vierge (3 exemplaires)</div>
                        <div><input type="checkbox"> Feuille d'émargement jury</div>
                        <div><input type="checkbox"> Rapport étudiant (si disponible)</div>
                        <div><input type="checkbox"> Convocations vérifiées</div>
                        <div><input type="checkbox"> Salle vérifiée et matériel préparé</div>
                        <div><input type="checkbox"> Eau et verres disposés</div>
                    </div>
                    
                    <p style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 10px;">
                        <strong>Notes :</strong> 
                        ______________________________________________________________
                    </p>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($soutenances) == 0): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        Aucune soutenance à préparer pour cette période
                    </p>
                <?php endif; ?>
                
                <div class="no-print" style="text-align: center; margin: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; font-size: 14pt;">
                        🖨️ Imprimer
                    </button>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
        
    } catch (Exception $e) {
        $message = "Erreur : " . $e->getMessage();
        $type_message = 'error';
    }
}

// Créer la table si nécessaire
$pdo->exec("
    CREATE TABLE IF NOT EXISTS dossiers_prepares (
        id INT PRIMARY KEY AUTO_INCREMENT,
        soutenance_id INT NOT NULL UNIQUE,
        prepare_par INT NOT NULL,
        date_preparation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        documents_imprimes BOOLEAN DEFAULT TRUE,
        materiel_prepare BOOLEAN DEFAULT TRUE,
        notes TEXT,
        FOREIGN KEY (soutenance_id) REFERENCES soutenances(id),
        FOREIGN KEY (prepare_par) REFERENCES utilisateurs(id),
        INDEX(soutenance_id)
    )
");

// Récupérer les dossiers à préparer (prochains jours)
$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(b.prenom, ' ', b.nom) as binome_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom,
           dp.id as dossier_prepare_id,
           dp.date_preparation,
           CONCAT(u.prenom, ' ', u.nom) as prepare_par_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs b ON p.binome_id = b.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    LEFT JOIN dossiers_prepares dp ON s.id = dp.soutenance_id
    LEFT JOIN utilisateurs u ON dp.prepare_par = u.id
    WHERE s.date_soutenance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute();
$dossiers = $stmt->fetchAll();

// Séparer préparés et non préparés
$non_prepares = [];
$prepares = [];

foreach ($dossiers as $d) {
    if ($d['dossier_prepare_id']) {
        $prepares[] = $d;
    } else {
        $non_prepares[] = $d;
    }
}

// Statistiques
$stats = [
    'total' => count($dossiers),
    'prepares' => count($prepares),
    'a_preparer' => count($non_prepares)
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Préparation des Dossiers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="bi bi-folder"></i> Préparation des Dossiers</h1>
                <p class="text-muted">Gestion des dossiers de soutenance</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#impressionModal">
                    <i class="bi bi-printer"></i> Liste d'impression
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
                        <h3 class="text-primary"><?= $stats['total'] ?></h3>
                        <p class="text-muted mb-0">Total dossiers (7 jours)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning"><?= $stats['a_preparer'] ?></h3>
                        <p class="text-muted mb-0">À préparer</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?= $stats['prepares'] ?></h3>
                        <p class="text-muted mb-0">Préparés</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dossiers à préparer -->
        <?php if (count($non_prepares) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        Dossiers à préparer (<?= count($non_prepares) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date & Heure</th>
                                    <th>Étudiant(s)</th>
                                    <th>Projet</th>
                                    <th>Filière</th>
                                    <th>Salle</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($non_prepares as $d): ?>
                                    <tr>
                                        <td>
                                            <strong><?= formater_date($d['date_soutenance'], 'd/m/Y') ?></strong><br>
                                            <small class="text-muted">
                                                <?= date('H:i', strtotime($d['heure_debut'])) ?> - 
                                                <?= date('H:i', strtotime($d['heure_fin'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($d['etudiant_nom']) ?>
                                            <?php if ($d['binome_nom']): ?>
                                                <br><small class="text-muted">& <?= htmlspecialchars($d['binome_nom']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars(substr($d['titre'], 0, 50)) ?>...</small>
                                        </td>
                                        <td><?= htmlspecialchars($d['filiere_nom']) ?></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($d['salle_nom']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-success" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#prepareModal"
                                                    data-soutenance-id="<?= $d['id'] ?>"
                                                    data-etudiant="<?= htmlspecialchars($d['etudiant_nom']) ?>"
                                                    data-date="<?= formater_date($d['date_soutenance'], 'd/m/Y H:i') ?>">
                                                <i class="bi bi-check-circle"></i> Marquer préparé
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Dossiers préparés -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-check-circle"></i>
                    Dossiers préparés (<?= count($prepares) ?>)
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($prepares) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date soutenance</th>
                                    <th>Étudiant(s)</th>
                                    <th>Salle</th>
                                    <th>Préparé le</th>
                                    <th>Par</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prepares as $p): ?>
                                    <tr>
                                        <td><?= formater_date($p['date_soutenance'], 'd/m/Y H:i') ?></td>
                                        <td><?= htmlspecialchars($p['etudiant_nom']) ?></td>
                                        <td><?= htmlspecialchars($p['salle_nom']) ?></td>
                                        <td><?= formater_date($p['date_preparation'], 'd/m/Y H:i') ?></td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($p['prepare_par_nom'] ?? 'Inconnu') ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted">Aucun dossier préparé pour le moment</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Préparer -->
    <div class="modal fade" id="prepareModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="marquer_prepare">
                    <input type="hidden" name="soutenance_id" id="modal_soutenance_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-folder-check"></i> Marquer le dossier comme préparé
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Étudiant :</strong> <span id="modal_etudiant_nom"></span><br>
                            <strong>Date :</strong> <span id="modal_date"></span>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Checklist de préparation :</strong>
                            <div class="form-check mt-2">
                                <input type="checkbox" name="documents_imprimes" 
                                       class="form-check-input" id="check_docs" checked>
                                <label class="form-check-label" for="check_docs">
                                    Documents imprimés (fiche, grilles, émargement)
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="materiel_prepare" 
                                       class="form-check-input" id="check_mat" checked>
                                <label class="form-check-label" for="check_mat">
                                    Matériel préparé (salle, eau, projecteur)
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes (optionnel)</label>
                            <textarea name="notes" 
                                      class="form-control" 
                                      rows="3"
                                      placeholder="Remarques ou informations complémentaires..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Confirmer la préparation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Impression -->
    <div class="modal fade" id="impressionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" target="_blank">
                    <input type="hidden" name="action" value="generer_liste_impression">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-printer"></i> Générer la liste d'impression
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Générez une liste imprimable avec tous les dossiers à préparer
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date début</label>
                            <input type="date" name="date_debut" 
                                   class="form-control" 
                                   value="<?= date('Y-m-d') ?>" 
                                   required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="date_fin" 
                                   class="form-control" 
                                   value="<?= date('Y-m-d', strtotime('+7 days')) ?>" 
                                   required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-printer"></i> Générer et imprimer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Remplir le modal de préparation avec les données
        const prepareModal = document.getElementById('prepareModal');
        prepareModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const soutenanceId = button.getAttribute('data-soutenance-id');
            const etudiantNom = button.getAttribute('data-etudiant');
            const date = button.getAttribute('data-date');
            
            document.getElementById('modal_soutenance_id').value = soutenanceId;
            document.getElementById('modal_etudiant_nom').textContent = etudiantNom;
            document.getElementById('modal_date').textContent = date;
        });
    </script>
</body>
</html>