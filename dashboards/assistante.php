<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'assistante') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();
$user_id = intval($_SESSION['user_id']);

// API (Ajax) : actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'];

    try {
        if ($action === 'toggle_salle') {
            // Toggle "disponible" flag for a salle
            $salle_id = intval($_POST['salle_id'] ?? 0);
            $dispo = isset($_POST['disponible']) && ($_POST['disponible'] == '1' || $_POST['disponible'] === 'true') ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE salles SET disponible = ? WHERE id = ?");
            $stmt->execute([$dispo, $salle_id]);
            echo json_encode(['status' => 'ok', 'salle_id' => $salle_id, 'disponible' => $dispo]);
            exit;
        }

        if ($action === 'save_checklist') {
            // Persist checklist items for this assistante and current date
            $items = $_POST['items'] ?? []; // expected associative array name => checked ('1'/'0')
            // ensure table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS assistante_checklist (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    cle VARCHAR(191) NOT NULL,
                    valeur TINYINT(1) NOT NULL DEFAULT 0,
                    date_jour DATE NOT NULL,
                    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX(user_id),
                    INDEX(date_jour)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $today = date('Y-m-d');
            // Delete today's entries for this user
            $stmt = $pdo->prepare("DELETE FROM assistante_checklist WHERE user_id = ? AND date_jour = ?");
            $stmt->execute([$user_id, $today]);
            // Insert new entries
            $insert = $pdo->prepare("INSERT INTO assistante_checklist (user_id, cle, valeur, date_jour) VALUES (?, ?, ?, ?)");
            foreach ($items as $k => $v) {
                $val = ($v === '1' || $v === 'true' || $v === 'on') ? 1 : 0;
                $insert->execute([$user_id, substr($k,0,190), $val, $today]);
            }
            echo json_encode(['status' => 'ok', 'saved' => count($items)]);
            exit;
        }

        if ($action === 'get_checklist') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS assistante_checklist (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    cle VARCHAR(191) NOT NULL,
                    valeur TINYINT(1) NOT NULL DEFAULT 0,
                    date_jour DATE NOT NULL,
                    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX(user_id),
                    INDEX(date_jour)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("SELECT cle, valeur FROM assistante_checklist WHERE user_id = ? AND date_jour = ?");
            $stmt->execute([$user_id, $today]);
            $rows = $stmt->fetchAll();
            $out = [];
            foreach ($rows as $r) $out[$r['cle']] = (int)$r['valeur'];
            echo json_encode(['status' => 'ok', 'items' => $out]);
            exit;
        }

        // Unknown action
        echo json_encode(['status' => 'error', 'message' => 'Action inconnue']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Page normal : lecture données
// 1) Soutenances aujourd'hui (détails)
$stmt = $pdo->prepare("
    SELECT s.*, p.titre, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom, sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE DATE(s.date_soutenance) = CURDATE()
    AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.heure_debut
");
$stmt->execute();
$soutenances_aujourdhui = $stmt->fetchAll();

// 2) Soutenances demain (compte)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as nb FROM soutenances 
    WHERE DATE(date_soutenance) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    AND statut IN ('planifiee', 'confirmee')
");
$stmt->execute();
$nb_demain = (int)$stmt->fetch()['nb'];

// 3) Convocations à envoyer (distinct soutenance_id)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT j.soutenance_id) as nb
    FROM jurys j
    JOIN soutenances s ON j.soutenance_id = s.id
    WHERE j.convocation_envoyee = 0
    AND s.date_soutenance >= CURDATE()
    AND s.statut IN ('planifiee', 'confirmee')
");
$stmt->execute();
$nb_convocations = (int)$stmt->fetch()['nb'];

// 4) Dossiers à préparer (prochains 2 jours)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as nb FROM soutenances 
    WHERE date_soutenance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    AND statut IN ('planifiee', 'confirmee')
");
$stmt->execute();
$nb_dossiers = (int)$stmt->fetch()['nb'];

// 5) PV à archiver
$stmt = $pdo->prepare("
    SELECT COUNT(*) as nb FROM soutenances 
    WHERE statut = 'terminee' AND pv_signe = 1
");
$stmt->execute();
$nb_pv = (int)$stmt->fetch()['nb'];

// 6) Charger toutes les salles
$stmt = $pdo->prepare("SELECT * FROM salles ORDER BY nom");
$stmt->execute();
$salles = $stmt->fetchAll();

// 7) Charger toutes les soutenances pour aujourd'hui afin d'évaluer l'état des salles en une seule passe
$stmt = $pdo->prepare("
    SELECT s.id, s.salle_id, s.heure_debut, s.heure_fin, DATE(s.date_soutenance) as date_soutenance
    FROM soutenances s
    WHERE DATE(s.date_soutenance) = CURDATE()
    AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.salle_id, s.heure_debut
");
$stmt->execute();
$today_slots = $stmt->fetchAll();

// Build map: salle_id => slots array
$salle_slots = [];
foreach ($today_slots as $slot) {
    $sid = $slot['salle_id'];
    if (!isset($salle_slots[$sid])) $salle_slots[$sid] = [];
    $salle_slots[$sid][] = $slot;
}

// Evaluate status for each salle: occupied / reserved / free
$salles_occupees = $salles_reservees = $salles_libres = 0;
$salles_with_status = []; // index by salle id

$current_time = date('H:i:s');

foreach ($salles as $s) {
    $sid = $s['id'];
    $status = 'free';
    $status_text = 'Libre';

    if (isset($salle_slots[$sid])) {
        // check if any slot covers current time
        $is_occupied = false;
        $has_future = false;
        foreach ($salle_slots[$sid] as $slot) {
            if ($current_time >= $slot['heure_debut'] && $current_time <= $slot['heure_fin']) {
                $is_occupied = true;
                break;
            }
            if ($slot['heure_debut'] > $current_time) {
                $has_future = true;
            }
        }
        if ($is_occupied) {
            $status = 'occupied';
            $status_text = 'Occupée';
            $salles_occupees++;
        } elseif ($has_future) {
            $status = 'reserved';
            $status_text = 'Réservée';
            $salles_reservees++;
        } else {
            $status = 'free';
            $status_text = 'Libre';
            $salles_libres++;
        }
    } else {
        // no slots today
        $status = $s['disponible'] ? 'free' : 'reserved';
        $status_text = $s['disponible'] ? 'Libre' : 'Réservée';
        if ($status === 'free') $salles_libres++; else $salles_reservees++;
    }

    $salles_with_status[$sid] = [
        'data' => $s,
        'status' => $status,
        'status_text' => $status_text
    ];
}

// 8) Charger checklist (aujourd'hui) pour l'assistante (si existe)
$today = date('Y-m-d');
$pdo->exec("
    CREATE TABLE IF NOT EXISTS assistante_checklist (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        cle VARCHAR(191) NOT NULL,
        valeur TINYINT(1) NOT NULL DEFAULT 0,
        date_jour DATE NOT NULL,
        date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(date_jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$stmt = $pdo->prepare("SELECT cle, valeur FROM assistante_checklist WHERE user_id = ? AND date_jour = ?");
$stmt->execute([$user_id, $today]);
$check_rows = $stmt->fetchAll();
$checklist_state = [];
foreach ($check_rows as $r) $checklist_state[$r['cle']] = (int)$r['valeur'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Assistante</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
        .container-fluid { display: flex; min-height: 100vh; padding: 0; }
        .sidebar { width: 240px; background: white; padding: 1.5rem; box-shadow: 2px 0 8px rgba(0,0,0,0.05); position: fixed; height: 100vh; overflow-y: auto; overflow-x: hidden; }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; text-decoration: none; }
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .nav-link.active { background: #cffafe; color: #0369a1; font-weight: 500; }
        .nav-link i { font-size: 1.125rem; }
        .nav-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
        .nav-footer .nav-link { color: #ef4444; }
        .nav-footer .nav-link:hover { background: #fee2e2; }
        .main-content { flex: 1; padding: 2rem; margin-left: 240px; width: 100%; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
        .page-subtitle { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        .header-actions { display: flex; align-items: center; gap: 1rem; }
        .notification-btn { position: relative; width: 40px; height: 40px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
        .notification-btn:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .notification-badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: white; font-size: 0.625rem; font-weight: 600; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .user-menu { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; background: white; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
        .user-menu:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-info { text-align: left; }
        .user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .user-role { font-size: 0.75rem; color: #64748b; }
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .stat-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-4px); }
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .stat-label { font-size: 0.875rem; color: #64748b; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .stat-icon.cyan { background: #cffafe; color: #0891b2; }
        .stat-icon.blue { background: #dbeafe; color: #0284c7; }
        .stat-icon.yellow { background: #fef3c7; color: #f59e0b; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
        .stat-sublabel { font-size: 0.875rem; color: #64748b; }
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; }
        .section-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
        .section-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
        .section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        .soutenance-item { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.75rem; transition: all 0.2s; }
        .soutenance-item:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); }
        .soutenance-time { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.25rem; }
        .soutenance-name { font-size: 0.875rem; color: #0f172a; margin-bottom: 0.25rem; }
        .soutenance-room { font-size: 0.75rem; color: #64748b; }
        .salle-item { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s; }
        .salle-item:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); }
        .salle-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .salle-capacity { font-size: 0.75rem; color: #64748b; }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .status-badge.occupied { background: #fee2e2; color: #991b1b; }
        .status-badge.reserved { background: #fef3c7; color: #92400e; }
        .status-badge.free { background: #d1fae5; color: #065f46; }
        .action-btn { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.75rem; }
        .action-btn:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); color: #0f172a; }
        .action-btn i { font-size: 1.25rem; color: #0891b2; }
        .checklist { display:flex; flex-direction:column; gap:0.75rem; }
        .save-checklist-btn { margin-top: 0.75rem; }
        .salle-extra {
            transition: max-height 0.25s ease, opacity 0.25s ease;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="sidebar">
            <a href="/Soutenances_PFE/dashboards/assistante.php" class="brand">
                <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
            </a>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/Soutenances_PFE/dashboards/assistante.php" class="nav-link active">
                        <i class="bi bi-house-door"></i><span>Tableau de bord</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/salles/gestion.php" class="nav-link">
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
                <li class="nav-item">
                    <a href="/Soutenances_PFE/documents/archivage.php" class="nav-link">
                        <i class="bi bi-archive"></i><span>Archivage</span>
                    </a>
                </li>
            </ul>
            
            <div class="nav-footer">
                <a href="/Soutenances_PFE/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i><span>Déconnexion</span>
                </a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Tableau de bord</h1>
                    <p class="page-subtitle">Gestion administrative des soutenances</p>
                </div>
                <div class="header-actions">
                    <?php if ($nb_convocations > 0 || $nb_dossiers > 0): ?>
                    <div class="notification-btn" title="Notifications">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span class="notification-badge"><?= ($nb_convocations + $nb_dossiers) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="user-menu">
                        <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                        <div class="user-info">
                            <div class="user-name">Mme. <?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div>
                            <div class="user-role">Assistante</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Soutenances aujourd'hui</div>
                        <div class="stat-icon cyan"><i class="bi bi-calendar-event"></i></div>
                    </div>
                    <div class="stat-value"><?= count($soutenances_aujourdhui) ?></div>
                    <div class="stat-sublabel">Planifiées et confirmées</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Demain</div>
                        <div class="stat-icon blue"><i class="bi bi-calendar2"></i></div>
                    </div>
                    <div class="stat-value"><?= $nb_demain ?></div>
                    <div class="stat-sublabel">Soutenances prévues</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Dossiers à préparer</div>
                        <div class="stat-icon yellow"><i class="bi bi-folder"></i></div>
                    </div>
                    <div class="stat-value"><?= $nb_dossiers ?></div>
                    <div class="stat-sublabel">Prochains 2 jours</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Convocations</div>
                        <div class="stat-icon purple"><i class="bi bi-send"></i></div>
                    </div>
                    <div class="stat-value"><?= $nb_convocations ?></div>
                    <div class="stat-sublabel">À envoyer</div>
                </div>
            </div>
            
            <div class="content-grid">
                <div>
                    <div class="section-card">
                        <h3 class="section-title"><i class="bi bi-calendar-event"></i> Soutenances du jour</h3>
                        
                        <?php if (count($soutenances_aujourdhui) > 0): ?>
                            <?php foreach ($soutenances_aujourdhui as $sout): ?>
                            <div class="soutenance-item">
                                <div class="soutenance-time">
                                    <?= date('H:i', strtotime($sout['heure_debut'])) ?> - <?= date('H:i', strtotime($sout['heure_fin'])) ?>
                                </div>
                                <div class="soutenance-name"><strong><?= nettoyer($sout['etudiant_nom']) ?></strong></div>
                                <div class="soutenance-room">
                                    <i class="bi bi-geo-alt"></i> <?= nettoyer($sout['salle_nom']) ?> • <?= nettoyer($sout['filiere_nom']) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune soutenance aujourd'hui</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-card">
                        <h3 class="section-title"><i class="bi bi-lightning-charge"></i> Actions rapides</h3>
                        
                        <a href="/Soutenances_PFE/documents/dossiers.php" class="action-btn">
                            <i class="bi bi-folder"></i><span>Préparer les dossiers (<?= $nb_dossiers ?>)</span>
                        </a>
                        
                        <a href="/Soutenances_PFE/documents/convocations.php" class="action-btn">
                            <i class="bi bi-send"></i><span>Envoyer convocations (<?= $nb_convocations ?>)</span>
                        </a>
                        
                        <a href="/Soutenances_PFE/salles/gestion.php" class="action-btn">
                            <i class="bi bi-building"></i><span>Gérer les salles</span>
                        </a>
                        
                        <a href="/Soutenances_PFE/documents/archivage.php" class="action-btn">
                            <i class="bi bi-archive"></i><span>Archiver PV (<?= $nb_pv ?>)</span>
                        </a>
                    </div>

                    <div class="section-card">
                        <h3 class="section-title"><i class="bi bi-list-check"></i> Checklist du jour</h3>
                        <form id="checklist-form">
                            <div class="checklist">
                                <?php
                                $defaults = [
                                    'verifier_salles' => 'Vérifier les salles',
                                    'preparer_materiel' => 'Préparer le matériel',
                                    'distribuer_dossiers' => 'Distribuer les dossiers',
                                    'recuperer_pv' => 'Récupérer les PV',
                                    'archiver_documents' => 'Archiver les documents'
                                ];
                                foreach ($defaults as $key => $label):
                                    $checked = isset($checklist_state[$key]) && $checklist_state[$key] ? 'checked' : '';
                                ?>
                                <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.875rem;">
                                    <input type="checkbox" name="<?= htmlspecialchars($key) ?>" <?= $checked ?> >
                                    <span><?= htmlspecialchars($label) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="save-checklist" class="btn btn-primary save-checklist-btn">Enregistrer la checklist</button>
                            <span id="checklist-msg" style="margin-left:0.75rem;"></span>
                        </form>
                    </div>

                </div>
                
                <div>
                    <div class="section-card">
                        <h3 class="section-title" style="font-size: 1rem;"><i class="bi bi-building"></i> État des salles</h3>

                        <?php
                        $max_display = 50;
                        $count_salles = count($salles);
                        foreach ($salles as $index => $salle):
                            if ($index >= $max_display) break;
                            $sid = $salle['id'];
                            $is_extra = $index >= 3; // afficher seulement 3 au départ
                            $extra_class = $is_extra ? 'salle-extra d-none' : '';
                            $entry = $salles_with_status[$sid] ?? ['data'=>$salle,'status'=>'free','status_text'=>$salle['disponible'] ? 'Libre' : 'Réservée'];
                            $status = $entry['status'];
                            $status_text = $entry['status_text'];
                        ?>
                        <div class="salle-item <?= $extra_class ?>" data-salle-id="<?= $sid ?>">
                            <div>
                                <div class="salle-name"><?= nettoyer($salle['nom']) ?></div>
                                <div class="salle-capacity"><?= $salle['capacite'] ?> places • <?= nettoyer($salle['batiment'] ?? '') ?></div>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <span class="status-badge <?= $status ?>"><?= $status_text ?></span>
                                <button class="btn btn-sm btn-outline-secondary toggle-dispo-btn" data-salle-id="<?= $sid ?>">
                                    <?= $salle['disponible'] ? 'Marquer non dispo' : 'Marquer dispo' ?>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($count_salles > 3): ?>
                            <div style="margin-top: 1rem; text-align: center;">
                                <button id="voir-plus-btn" class="btn btn-sm btn-outline-primary">Voir plus</button>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 1rem;">
                        </div>
                    </div>
                    
                
                </div>
            </div>
        </div>
    </div>

<script>
async function postFormData(data) {
    const resp = await fetch(location.href, {
        method: 'POST',
        body: new URLSearchParams(data),
        headers: { 'Accept': 'application/json' }
    });
    return resp.json();
}

// Toggle salle disponible
document.querySelectorAll('.toggle-dispo-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const salleId = btn.getAttribute('data-salle-id');
        // determine current label to toggle
        const parent = btn.closest('.salle-item');
        const badge = parent.querySelector('.status-badge');
        const availableLabel = btn.innerText.includes('Marquer non dispo') ? true : (btn.innerText.includes('Marquer dispo') ? false : null);
        // We will request to set disponible opposite of current button text
        const setDispo = btn.innerText.includes('Marquer dispo') ? 1 : 0;
        btn.disabled = true;
        btn.innerText = '...';
        try {
            const res = await postFormData({ action: 'toggle_salle', salle_id: salleId, disponible: setDispo });
            if (res.status === 'ok') {
                // update button and badge
                const newDispon = res.disponible ? 1 : 0;
                btn.innerText = newDispon ? 'Marquer non dispo' : 'Marquer dispo';
                // update badge style only (do not change occupied/reserved)
                const badgeEl = parent.querySelector('.status-badge');
                if (badgeEl) {
                    // keep existing status class (occupied/reserved/free) but if free/reserved adjust text fallback
                    if (newDispon && badgeEl.classList.contains('reserved')) {
                        // If previously reserved because not disponible, mark free
                        badgeEl.classList.remove('reserved');
                        badgeEl.classList.add('free');
                        badgeEl.innerText = 'Libre';
                    } else if (!newDispon && badgeEl.classList.contains('free')) {
                        badgeEl.classList.remove('free');
                        badgeEl.classList.add('reserved');
                        badgeEl.innerText = 'Réservée';
                    }
                }
            } else {
                alert('Erreur: ' + (res.message || 'Impossible de mettre à jour'));
                btn.innerText = setDispo ? 'Marquer non dispo' : 'Marquer dispo';
            }
        } catch (err) {
            alert('Erreur réseau');
            btn.innerText = setDispo ? 'Marquer non dispo' : 'Marquer dispo';
        } finally {
            btn.disabled = false;
        }
    });
});

// Checklist save
document.getElementById('save-checklist').addEventListener('click', async () => {
    const form = document.getElementById('checklist-form');
    const inputs = form.querySelectorAll('input[type="checkbox"]');
    const items = {};
    inputs.forEach(i => {
        items[i.name] = i.checked ? '1' : '0';
    });
    const data = { action: 'save_checklist' };
    // append items as items[key] => value (PHP expects items[] associative)
    for (const k in items) data['items['+k+']'] = items[k];

    document.getElementById('save-checklist').disabled = true;
    document.getElementById('checklist-msg').innerText = 'Enregistrement...';
    try {
        const res = await postFormData(data);
        if (res.status === 'ok') {
            document.getElementById('checklist-msg').innerText = 'Enregistré ✓';
            setTimeout(()=>document.getElementById('checklist-msg').innerText = '', 3000);
        } else {
            document.getElementById('checklist-msg').innerText = 'Erreur lors de l\'enregistrement';
        }
    } catch (e) {
        document.getElementById('checklist-msg').innerText = 'Erreur réseau';
    } finally {
        document.getElementById('save-checklist').disabled = false;
    }
});

const voirBtn = document.getElementById('voir-plus-btn');
if (voirBtn) {
    voirBtn.addEventListener('click', function() {
        const extras = document.querySelectorAll('.salle-extra');
        if (!extras.length) return;
        const hidden = extras[0].classList.contains('d-none');
        extras.forEach(el => {
            if (hidden) {
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        });
        voirBtn.innerText = hidden ? 'Voir moins' : 'Voir plus';
        if (!hidden) {
            voirBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
