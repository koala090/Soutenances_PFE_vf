<?php
// /Soutenances_PFE/salles/gestion.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

demarrer_session();

// Vérification authentification
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'assistante') {
    header('Location: /Soutenances_PFE/login.php');
    exit;
}

// Récupérer infos utilisateur
$user = get_user_info();

// Message de succès/erreur
$message = '';
$error = '';

// ========================================
// TRAITEMENT DES ACTIONS POST
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // AJOUTER UNE SALLE
        if ($action === 'ajouter') {
            $nom = trim($_POST['nom'] ?? '');
            $batiment = trim($_POST['batiment'] ?? '');
            $etage = trim($_POST['etage'] ?? '');
            $capacite = intval($_POST['capacite'] ?? 0);
            $equipements = json_encode($_POST['equipements'] ?? []);
            $disponible = isset($_POST['disponible']) ? 1 : 0;
            
            if (empty($nom) || empty($batiment) || $capacite < 1) {
                throw new Exception("Tous les champs obligatoires doivent être remplis");
            }
            
            $stmt = $pdo->prepare("INSERT INTO salles (nom, batiment, etage, capacite, equipements, disponible) 
                                   VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $batiment, $etage, $capacite, $equipements, $disponible]);
            
            $message = "✅ Salle ajoutée avec succès !";
        }
        
        // MODIFIER UNE SALLE
        elseif ($action === 'modifier') {
            $id = intval($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $batiment = trim($_POST['batiment'] ?? '');
            $etage = trim($_POST['etage'] ?? '');
            $capacite = intval($_POST['capacite'] ?? 0);
            $equipements = json_encode($_POST['equipements'] ?? []);
            $disponible = isset($_POST['disponible']) ? 1 : 0;
            
            if (empty($nom) || empty($batiment) || $capacite < 1) {
                throw new Exception("Tous les champs obligatoires doivent être remplis");
            }
            
            $stmt = $pdo->prepare("UPDATE salles SET nom = ?, batiment = ?, etage = ?, capacite = ?, equipements = ?, disponible = ? WHERE id = ?");
            $stmt->execute([$nom, $batiment, $etage, $capacite, $equipements, $disponible, $id]);
            
            $message = "✅ Salle modifiée avec succès !";
        }
        
    } catch (Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ========================================
// SUPPRIMER UNE SALLE (GET)
// ========================================
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        
        // Vérifier si la salle est utilisée
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM soutenances WHERE salle_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $error = "❌ Impossible de supprimer : cette salle est utilisée dans " . $result['count'] . " soutenance(s)";
        } else {
            $stmt = $pdo->prepare("DELETE FROM salles WHERE id = ?");
            $stmt->execute([$id]);
            $message = "✅ Salle supprimée avec succès !";
        }
    } catch (Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ========================================
// RÉCUPÉRATION DES DONNÉES
// ========================================

// Toutes les salles
$stmt = $pdo->prepare("SELECT * FROM salles ORDER BY batiment, nom");
$stmt->execute();
$salles = $stmt->fetchAll();

// Statistiques
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN disponible = 1 THEN 1 ELSE 0 END) as disponibles,
        SUM(capacite) as capacite_totale,
        ROUND(AVG(capacite)) as capacite_moyenne
    FROM salles
");
$stmt->execute();
$stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Salles - PFE Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
        
        .container-fluid { display: flex; min-height: 100vh; padding: 0; }
        
        /* Sidebar */
        .sidebar { width: 240px; background: white; padding: 1.5rem; box-shadow: 2px 0 8px rgba(0,0,0,0.05); position: fixed; height: 100vh; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; text-decoration: none; }
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; }
        .nav-link.active { background: #cffafe; color: #0369a1; font-weight: 500; }
        .nav-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
        
        /* Main Content */
        .main-content { flex: 1; padding: 2rem; margin-left: 240px; width: 100%; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
        .page-subtitle { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        
        /* Stats Cards */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; }
        .stat-label { font-size: 0.875rem; color: #64748b; margin-bottom: 0.5rem; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; }
        .stat-icon { float: right; font-size: 2rem; opacity: 0.2; }
        
        /* Table */
        .section-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 1.5rem; }
        .section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; }
        
        table { width: 100%; }
        th { background: #f8fafc; padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: #64748b; }
        td { padding: 0.75rem; border-bottom: 1px solid #e2e8f0; }
        
        .badge { padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        
        .equip-tag { background: #dbeafe; color: #1e40af; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; margin-right: 0.25rem; display: inline-block; }
        
        /* Buttons */
        .btn-icon { padding: 0.5rem; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
        .btn-edit { background: #fef3c7; color: #92400e; }
        .btn-edit:hover { background: #fde68a; }
        .btn-delete { background: #fee2e2; color: #991b1b; }
        .btn-delete:hover { background: #fecaca; }
        
        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto; }
        .modal-content { background: white; max-width: 600px; margin: 2rem auto; border-radius: 12px; padding: 2rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #0f172a; font-size: 0.875rem; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; }
        .form-control:focus { outline: none; border-color: #0891b2; }
        .checkbox-group { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        .checkbox-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; cursor: pointer; }
        
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Sidebar -->
        <div class="sidebar">
            <a href="/Soutenances_PFE/dashboards/assistante.php" class="brand">
                <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
            </a>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/Soutenances_PFE/dashboards/assistante.php" class="nav-link">
                        <i class="bi bi-house-door"></i><span>Tableau de bord</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/salles/gestion.php" class="nav-link active">
                        <i class="bi bi-building"></i><span>Gestion Salles</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/documents/dossiers.php" class="nav-link">
                        <i class="bi bi-folder"></i><span>Dossiers</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/documents/convocations.php" class="nav-link">
                        <i class="bi bi-send"></i><span>Convocations</span>
                    </a>
                </li>
            </ul>
            
            <div class="nav-footer">
                <a href="/Soutenances_PFE/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i><span>Déconnexion</span>
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title"><i class="bi bi-building"></i> Gestion des Salles</h1>
                    <p class="page-subtitle">Gérer les salles de soutenance</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('add')">
                    <i class="bi bi-plus-lg"></i> Ajouter une salle
                </button>
            </div>
            
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <i class="bi bi-building stat-icon"></i>
                    <div class="stat-label">Total des salles</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-check-circle stat-icon"></i>
                    <div class="stat-label">Salles disponibles</div>
                    <div class="stat-value"><?= $stats['disponibles'] ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-people stat-icon"></i>
                    <div class="stat-label">Capacité totale</div>
                    <div class="stat-value"><?= $stats['capacite_totale'] ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-bar-chart stat-icon"></i>
                    <div class="stat-label">Capacité moyenne</div>
                    <div class="stat-value"><?= $stats['capacite_moyenne'] ?></div>
                </div>
            </div>
            
            <!-- Table -->
            <div class="section-card">
                <h3 class="section-title"><i class="bi bi-list"></i> Liste des salles</h3>
                
                <table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Bâtiment</th>
                            <th>Étage</th>
                            <th>Capacité</th>
                            <th>Équipements</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salles as $salle): ?>
                        <tr>
                            <td><strong><?= nettoyer($salle['nom']) ?></strong></td>
                            <td><?= nettoyer($salle['batiment']) ?></td>
                            <td><?= nettoyer($salle['etage']) ?></td>
                            <td><i class="bi bi-people"></i> <?= $salle['capacite'] ?></td>
                            <td>
                                <?php 
                                $equipements = json_decode($salle['equipements'], true);
                                if ($equipements && count($equipements) > 0):
                                    foreach (array_slice($equipements, 0, 3) as $equip):
                                ?>
                                    <span class="equip-tag"><?= nettoyer($equip) ?></span>
                                <?php 
                                    endforeach;
                                    if (count($equipements) > 3):
                                ?>
                                    <span class="equip-tag">+<?= (count($equipements) - 3) ?></span>
                                <?php 
                                    endif;
                                endif;
                                ?>
                            </td>
                            <td>
                                <?php if ($salle['disponible']): ?>
                                    <span class="badge badge-success">Disponible</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Indisponible</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-icon btn-edit" onclick='editSalle(<?= json_encode($salle, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-icon btn-delete" onclick="confirmDelete(<?= $salle['id'] ?>)" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Modal -->
    <div id="salleModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title" id="modalTitle">Ajouter une salle</h2>
            <form method="POST" id="salleForm">
                <input type="hidden" name="action" id="formAction" value="ajouter">
                <input type="hidden" name="id" id="salleId">
                
                <div class="form-group">
                    <label class="form-label">Nom de la salle *</label>
                    <input type="text" name="nom" id="nom" class="form-control" required placeholder="Ex: Amphi 1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bâtiment *</label>
                    <input type="text" name="batiment" id="batiment" class="form-control" required placeholder="Ex: Bâtiment Principal">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Étage *</label>
                    <input type="text" name="etage" id="etage" class="form-control" required placeholder="Ex: RDC, 1er">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Capacité *</label>
                    <input type="number" name="capacite" id="capacite" class="form-control" required min="1" placeholder="Ex: 120">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Équipements</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Projecteur">
                            <span>Projecteur</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Tableau blanc">
                            <span>Tableau blanc</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Visioconférence">
                            <span>Visioconférence</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Climatisation">
                            <span>Climatisation</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Ordinateur">
                            <span>Ordinateur</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="equipements[]" value="Microphone">
                            <span>Microphone</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="disponible" id="disponible" checked>
                        <span>Salle disponible</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(mode) {
            document.getElementById('salleModal').style.display = 'block';
            if (mode === 'add') {
                document.getElementById('modalTitle').innerHTML = 'Ajouter une salle';
                document.getElementById('formAction').value = 'ajouter';
                document.getElementById('salleForm').reset();
            }
        }
        
        function closeModal() {
            document.getElementById('salleModal').style.display = 'none';
        }
        
        function editSalle(salle) {
            document.getElementById('salleModal').style.display = 'block';
            document.getElementById('modalTitle').innerHTML = 'Modifier la salle';
            document.getElementById('formAction').value = 'modifier';
            document.getElementById('salleId').value = salle.id;
            document.getElementById('nom').value = salle.nom;
            document.getElementById('batiment').value = salle.batiment;
            document.getElementById('etage').value = salle.etage;
            document.getElementById('capacite').value = salle.capacite;
            document.getElementById('disponible').checked = salle.disponible == 1;
            
            // Décocher tous les équipements
            document.querySelectorAll('input[name="equipements[]"]').forEach(cb => cb.checked = false);
            
            // Cocher les équipements de la salle
            const equipements = JSON.parse(salle.equipements || '[]');
            equipements.forEach(equip => {
                const checkbox = document.querySelector(`input[value="${equip}"]`);
                if (checkbox) checkbox.checked = true;
            });
        }
        
        function confirmDelete(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette salle ?')) {
                window.location.href = '?action=supprimer&id=' + id;
            }
        }
        
        // Fermer modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('salleModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
