<?php
// /Soutenances_PFE/salles/liste.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

demarrer_session();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'assistante') {
    header('Location: /Soutenances_PFE/login.php');
    exit;
}

$user = get_user_info();

// Récupérer toutes les salles avec statistiques
$stmt = $pdo->prepare("
    SELECT s.*, 
           (SELECT COUNT(*) FROM soutenances WHERE salle_id = s.id AND date_soutenance >= CURDATE()) as soutenances_futures
    FROM salles s 
    ORDER BY s.batiment, s.nom
");
$stmt->execute();
$salles = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Salles - PFE Manager</title>
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
        
        /* Grid de cartes */
        .salles-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        
        .salle-card { 
            background: white; 
            border-radius: 12px; 
            border: 1px solid #e2e8f0; 
            padding: 1.5rem; 
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .salle-card:hover { 
            transform: translateY(-8px); 
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .salle-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0891b2, #06b6d4);
        }
        
        .salle-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: start; 
            margin-bottom: 1rem; 
        }
        
        .salle-name { 
            font-size: 1.25rem; 
            font-weight: 700; 
            color: #0f172a; 
            margin-bottom: 0.25rem;
        }
        
        .salle-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .salle-info { 
            display: flex; 
            flex-direction: column; 
            gap: 0.75rem; 
            margin: 1rem 0;
        }
        
        .info-row { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .info-row i { 
            width: 20px; 
            color: #0891b2; 
            font-size: 1rem;
        }
        
        .status-badge { 
            padding: 0.375rem 0.875rem; 
            border-radius: 20px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            display: inline-block;
        }
        
        .status-libre { background: #d1fae5; color: #065f46; }
        .status-occupe { background: #fee2e2; color: #991b1b; }
        
        .equipements { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 0.5rem; 
            margin-top: 1rem;
        }
        
        .equip-badge { 
            background: #dbeafe; 
            color: #1e40af; 
            padding: 0.375rem 0.75rem; 
            border-radius: 6px; 
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .salle-actions { 
            margin-top: 1.5rem; 
            padding-top: 1rem; 
            border-top: 1px solid #f1f5f9; 
            display: flex; 
            gap: 0.75rem; 
        }
        
        .btn-action { 
            flex: 1;
            padding: 0.625rem; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            background: white;
            font-size: 0.875rem; 
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #0f172a;
        }
        
        .btn-action:hover { 
            background: #f8fafc; 
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn-edit { border-color: #fbbf24; color: #92400e; }
        .btn-edit:hover { background: #fef3c7; border-color: #f59e0b; }
        
        .btn-delete { border-color: #f87171; color: #991b1b; }
        .btn-delete:hover { background: #fee2e2; border-color: #ef4444; }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
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
                    <h1 class="page-title"><i class="bi bi-grid-3x3-gap"></i> Liste des Salles</h1>
                    <p class="page-subtitle">Vue en cartes des salles disponibles</p>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="/Soutenances_PFE/salles/ajouter.php" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Ajouter
                    </a>
                    <a href="/Soutenances_PFE/salles/gestion.php" class="btn btn-outline-secondary">
                        <i class="bi bi-table"></i> Vue tableau
                    </a>
                </div>
            </div>
            
            <?php if (count($salles) > 0): ?>
                <div class="salles-grid">
                    <?php foreach ($salles as $salle): ?>
                        <div class="salle-card">
                            <div class="salle-header">
                                <div style="flex: 1;">
                                    <div class="salle-name"><?= nettoyer($salle['nom']) ?></div>
                                    <span class="status-badge <?= $salle['disponible'] ? 'status-libre' : 'status-occupe' ?>">
                                        <?= $salle['disponible'] ? 'Disponible' : 'Indisponible' ?>
                                    </span>
                                </div>
                                <div class="salle-icon">
                                    <i class="bi bi-building"></i>
                                </div>
                            </div>
                            
                            <div class="salle-info">
                                <div class="info-row">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span><strong><?= nettoyer($salle['batiment']) ?></strong> • Étage <?= nettoyer($salle['etage']) ?></span>
                                </div>
                                <div class="info-row">
                                    <i class="bi bi-people-fill"></i>
                                    <span><?= $salle['capacite'] ?> places</span>
                                </div>
                                <?php if ($salle['soutenances_futures'] > 0): ?>
                                    <div class="info-row">
                                        <i class="bi bi-calendar-event"></i>
                                        <span><?= $salle['soutenances_futures'] ?> soutenance(s) à venir</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php 
                            $equipements = json_decode($salle['equipements'], true);
                            if ($equipements && count($equipements) > 0):
                            ?>
                                <div class="equipements">
                                    <?php foreach ($equipements as $equip): ?>
                                        <span class="equip-badge">
                                            <i class="bi bi-check2"></i> <?= nettoyer($equip) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="salle-actions">
                                <a href="/Soutenances_PFE/salles/modifier.php?id=<?= $salle['id'] ?>" class="btn-action btn-edit">
                                    <i class="bi bi-pencil"></i> Modifier
                                </a>
                                <button onclick="confirmDelete(<?= $salle['id'] ?>)" class="btn-action btn-delete">
                                    <i class="bi bi-trash"></i> Supprimer
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h3>Aucune salle enregistrée</h3>
                    <p>Commencez par ajouter une salle</p>
                    <a href="/Soutenances_PFE/salles/ajouter.php" class="btn btn-primary mt-3">
                        <i class="bi bi-plus-lg"></i> Ajouter une salle
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function confirmDelete(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette salle ?')) {
                window.location.href = '/Soutenances_PFE/salles/gestion.php?action=supprimer&id=' + id;
            }
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


