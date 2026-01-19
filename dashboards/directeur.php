<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'directeur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Statistiques globales toutes filières
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_projets,
        SUM(CASE WHEN statut IN ('planifie', 'soutenu') THEN 1 ELSE 0 END) as soutenances_planifiees,
        SUM(CASE WHEN statut = 'soutenu' THEN 1 ELSE 0 END) as soutenances_terminees,
        SUM(CASE WHEN encadrant_id IS NULL THEN 1 ELSE 0 END) as sans_encadrant
    FROM projets
    WHERE annee_universitaire = '2024-2025'
");
$stats_globales = $stmt->fetch();

// Statistiques par filière
$stmt = $pdo->query("
    SELECT f.nom as filiere,
           COUNT(p.id) as nb_projets,
           SUM(CASE WHEN p.statut = 'soutenu' THEN 1 ELSE 0 END) as soutenus,
           AVG(CASE WHEN s.note_finale IS NOT NULL THEN s.note_finale END) as moyenne
    FROM filieres f
    LEFT JOIN projets p ON f.id = p.filiere_id AND p.annee_universitaire = '2024-2025'
    LEFT JOIN soutenances s ON p.id = s.projet_id
    GROUP BY f.id
    ORDER BY f.nom
");
$stats_filieres = $stmt->fetchAll();

// PV à signer
$stmt = $pdo->query("
    SELECT s.*, p.titre, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom, sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE s.statut = 'terminee' AND s.pv_signe = 0 AND s.pv_genere = 1
    ORDER BY s.date_soutenance DESC
    LIMIT 10
");
$pv_a_signer = $stmt->fetchAll();

// Soutenances à venir (7 prochains jours)
$stmt = $pdo->query("
    SELECT s.*, p.titre, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom, sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE s.date_soutenance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.date_soutenance, s.heure_debut
    LIMIT 10
");
$soutenances_prochaines = $stmt->fetchAll();

// Taux de réussite
$taux_reussite = $stats_globales['soutenances_terminees'] > 0 
    ? round(($stats_globales['soutenances_terminees'] / $stats_globales['soutenances_terminees']) * 100) 
    : 0;

// Alertes
$nb_alertes = $stats_globales['sans_encadrant'] + count($pv_a_signer);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Directeur</title>
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
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .nav-link.active { background: #fef3c7; color: #92400e; font-weight: 500; }
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
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-info { text-align: left; }
        .user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .user-role { font-size: 0.75rem; color: #64748b; }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .stat-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-4px); }
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .stat-label { font-size: 0.875rem; color: #64748b; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .stat-icon.orange { background: #fed7aa; color: #ea580c; }
        .stat-icon.blue { background: #dbeafe; color: #0284c7; }
        .stat-icon.green { background: #d1fae5; color: #10b981; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
        .stat-sublabel { font-size: 0.875rem; color: #64748b; }
        
        .content-grid { display: grid; grid-template-columns: 1fr 400px; gap: 1.5rem; }
        .section-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
        .section-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
        .section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .filiere-item { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.75rem; transition: all 0.2s; }
        .filiere-item:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); }
        .filiere-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; }
        .filiere-stats { display: flex; gap: 1rem; font-size: 0.75rem; color: #64748b; }
        
        .pv-item { padding: 1rem; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; margin-bottom: 0.75rem; transition: all 0.2s; }
        .pv-item:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); }
        .pv-title { font-size: 0.875rem; font-weight: 600; color: #92400e; margin-bottom: 0.25rem; }
        .pv-info { font-size: 0.75rem; color: #78350f; }
        
        .soutenance-item { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.75rem; }
        .soutenance-date { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; }
        .soutenance-info { font-size: 0.75rem; color: #64748b; }
        
        .btn-sign { padding: 0.5rem 1rem; background: #f59e0b; color: white; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .btn-sign:hover { background: #d97706; box-shadow: 0 4px 8px rgba(245,158,11,0.3); }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="sidebar">
        <a href="/Soutenances_PFE/dashboards/directeur.php" class="brand">
            <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
        </a>
        <ul class="nav-menu">
            <li class="nav-item"><a href="/Soutenances_PFE/dashboards/directeur.php" class="nav-link active"><i class="bi bi-house-door"></i><span>Tableau de bord</span></a></li>
            <li class="nav-item"><a href="/Soutenances_PFE/planning/planningglobal.php" class="nav-link"><i class="bi bi-calendar"></i><span>Planning global</span></a></li>
            <li class="nav-item"><a href="/Soutenances_PFE/documents/pv.php" class="nav-link"><i class="bi bi-file-earmark-check"></i><span>PV à signer</span><?php if (count($pv_a_signer) > 0): ?><span style="margin-left:auto;background:#ef4444;color:white;font-size:0.75rem;padding:0.125rem 0.5rem;border-radius:12px;font-weight:600;"><?= count($pv_a_signer) ?></span><?php endif; ?></a></li>
        </ul>
        <div class="nav-footer">
            <a href="/Soutenances_PFE/logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i><span>Déconnexion</span></a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div><h1 class="page-title">Vue d'ensemble - Direction</h1><p class="page-subtitle">Supervision des soutenances • Année 2024-2025</p></div>
            <div class="header-actions">
                <?php if ($nb_alertes > 0): ?><div class="notification-btn"><i class="bi bi-exclamation-triangle"></i><span class="notification-badge"><?= $nb_alertes ?></span></div><?php endif; ?>
                <div class="user-menu"><div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div><div class="user-info"><div class="user-name"><?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div><div class="user-role">Directeur</div></div></div>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card"><div class="stat-header"><div class="stat-label">Total Projets</div><div class="stat-icon orange"><i class="bi bi-folder"></i></div></div><div class="stat-value"><?= $stats_globales['total_projets'] ?></div><div class="stat-sublabel">Toutes filières confondues</div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-label">Soutenances Planifiées</div><div class="stat-icon blue"><i class="bi bi-calendar-check"></i></div></div><div class="stat-value"><?= $stats_globales['soutenances_planifiees'] ?></div><div class="stat-sublabel">Planifiées et terminées</div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-label">Taux de Réussite</div><div class="stat-icon green"><i class="bi bi-trophy"></i></div></div><div class="stat-value"><?= $taux_reussite ?>%</div><div class="stat-sublabel"><?= $stats_globales['soutenances_terminees'] ?> soutenances terminées</div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-label">PV à Signer</div><div class="stat-icon purple"><i class="bi bi-pen"></i></div></div><div class="stat-value"><?= count($pv_a_signer) ?></div><div class="stat-sublabel">En attente de signature</div></div>
        </div>

        <?php if ($stats_globales['sans_encadrant'] > 0): ?>
        <div style="background:#fffbeb;border:1px solid #fef3c7;border-left:4px solid #f59e0b;padding:1rem 1.25rem;border-radius:8px;margin-bottom:1.5rem;"><strong style="color:#92400e;"><i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> <?= $stats_globales['sans_encadrant'] ?> projet(s) sans encadrant nécessitent une attention</strong></div>
        <?php endif; ?>

        <div class="content-grid">
            <div>
                <div class="section-card"><h3 class="section-title"><i class="bi bi-building"></i> Vue par filière</h3>
                    <?php foreach ($stats_filieres as $filiere): ?>
                    <div class="filiere-item"><div class="filiere-name"><?= nettoyer($filiere['filiere']) ?></div><div class="filiere-stats"><span><i class="bi bi-folder"></i> <?= $filiere['nb_projets'] ?> projets</span><span><i class="bi bi-check-circle"></i> <?= $filiere['soutenus'] ?> soutenus</span><?php if ($filiere['moyenne']): ?><span><i class="bi bi-star"></i> Moyenne: <?= number_format($filiere['moyenne'], 2) ?>/20</span><?php endif; ?></div></div>
                    <?php endforeach; ?>
                </div>

                <div class="section-card"><h3 class="section-title"><i class="bi bi-calendar-event"></i> Soutenances à venir (7 prochains jours)</h3>
                    <?php if (count($soutenances_prochaines) > 0): ?>
                        <?php foreach ($soutenances_prochaines as $sout): ?>
                        <div class="soutenance-item"><div class="soutenance-date"><?= formater_date($sout['date_soutenance'], 'd M Y') ?> à <?= date('H:i', strtotime($sout['heure_debut'])) ?></div><div class="soutenance-info" style="margin-bottom:0.25rem;"><strong><?= nettoyer($sout['etudiant_nom']) ?></strong> - <?= nettoyer($sout['filiere_nom']) ?></div><div class="soutenance-info"><i class="bi bi-geo-alt"></i> <?= nettoyer($sout['salle_nom']) ?></div></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <p style="text-align:center;color:#64748b;padding:2rem;">Aucune soutenance prévue cette semaine</p>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="section-card"><h3 class="section-title" style="font-size:1rem;"><i class="bi bi-pen"></i> PV à signer</h3>
                    <?php if (count($pv_a_signer) > 0): ?>
                        <?php foreach ($pv_a_signer as $pv): ?>
                        <div class="pv-item"><div class="pv-title"><?= nettoyer($pv['etudiant_nom']) ?></div><div class="pv-info" style="margin-bottom:0.5rem;"><?= nettoyer($pv['filiere_nom']) ?></div><div class="pv-info" style="margin-bottom:0.75rem;">Soutenu le <?= formater_date($pv['date_soutenance'], 'd M Y') ?></div><button class="btn-sign" onclick="window.location.href='/Soutenances_PFE/documents/signer-pv.php?id=<?= $pv['id'] ?>'"><i class="bi bi-pen"></i> Signer le PV</button></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <p style="text-align:center;color:#64748b;padding:1rem;">Aucun PV en attente</p>
                    <?php endif; ?>
                </div>

                <div class="section-card"><h3 class="section-title" style="font-size:1rem;"><i class="bi bi-lightning-charge"></i> Actions rapides</h3>
                    <a href="/Soutenances_PFE/projets/statistiques.php" style="display:flex;align-items:center;gap:0.75rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#0f172a;font-size:0.875rem;font-weight:500;transition:all 0.2s;margin-bottom:0.75rem;"><i class="bi bi-bar-chart" style="font-size:1.25rem;color:#f59e0b;"></i><span>Voir statistiques détaillées</span></a>
                    <a href="/Soutenances_PFE/planning/planningglobal.php" style="display:flex;align-items:center;gap:0.75rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#0f172a;font-size:0.875rem;font-weight:500;transition:all 0.2s;margin-bottom:0.75rem;"><i class="bi bi-calendar" style="font-size:1.25rem;color:#f59e0b;"></i><span>Consulter le planning</span></a>
                    <a href="/Soutenances_PFE/utilisateurs/gestion.php" style="display:flex;align-items:center;gap:0.75rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#0f172a;font-size:0.875rem;font-weight:500;transition:all 0.2s;"><i class="bi bi-people" style="font-size:1.25rem;color:#f59e0b;"></i><span>Gérer les utilisateurs</span></a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
