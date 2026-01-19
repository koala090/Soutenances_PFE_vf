<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Récupérer le projet de l'étudiant
$annee_uni = '2024-2025';
$stmt = $pdo->prepare("
    SELECT p.*,
           CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           enc.email as encadrant_email,
           enc.telephone as encadrant_tel,
           CONCAT(bin.prenom, ' ', bin.nom) as binome_nom,
           f.nom as filiere_nom
    FROM projets p
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    LEFT JOIN utilisateurs bin ON p.binome_id = bin.id
    LEFT JOIN filieres f ON p.filiere_id = f.id
    WHERE (p.etudiant_id = ? OR p.binome_id = ?)
      AND p.annee_universitaire = ?
    LIMIT 1
");
$stmt->execute([$user['id'], $user['id'], $annee_uni]);
$projet = $stmt->fetch();

// Messages non lus 
$nb_messages = 0;
if ($projet && isset($projet['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messages 
            WHERE projet_id = ? 
            AND emetteur_id != ? 
            AND lu = 0
        ");
        $stmt->execute([$projet['id'], $user['id']]);
        $nb_messages = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM messages 
                WHERE projet_id = ? 
                AND lu = 0
            ");
            $stmt->execute([$projet['id']]);
            $nb_messages = (int) $stmt->fetchColumn();
        } catch (PDOException $e2) {
            $nb_messages = 0;
        }
    }
}

// Soutenance
$soutenance = null;
if ($projet && in_array($projet['statut'], ['planifie', 'soutenu', 'planifiee'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, sa.nom as salle_nom, sa.batiment
        FROM soutenances s
        JOIN salles sa ON s.salle_id = sa.id
        WHERE s.projet_id = ?
        ORDER BY s.date_soutenance, s.heure_debut
        LIMIT 1
    ");
    $stmt->execute([$projet['id']]);
    $soutenance = $stmt->fetch();
}

// Calcul des jours restants
$date_limite_rapport = '2025-01-15'; //exemple
if ($soutenance) {
    $sout_datetime = $soutenance['date_soutenance'];
    if (!empty($soutenance['heure_debut'])) {
        $sout_datetime .= ' ' . $soutenance['heure_debut'];
    } else {
        $sout_datetime .= ' 00:00';
    }
    $diff_seconds = strtotime($sout_datetime) - time();
} else {
    $diff_seconds = strtotime($date_limite_rapport . ' 23:59:59') - time();
}
$jours_restants = (int) floor($diff_seconds / 86400);
if ($jours_restants < 0) $jours_restants = 0;

// Documents soumis
$nb_documents = 0;
if ($projet && isset($projet['id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rapports WHERE projet_id = ?");
    $stmt->execute([$projet['id']]);
    $nb_documents = (int) $stmt->fetchColumn();
}

// Traduction statut
$statut_fr = [
    'inscrit' => 'Inscrit',
    'encadrant_affecte' => 'Encadrant affecté',
    'en_cours' => 'En cours',
    'rapport_soumis' => 'Rapport soumis',
    'valide_encadrant' => 'Validé',
    'planifie' => 'Planifié',
    'planifiee' => 'Planifiée',
    'soutenu' => 'Soutenu'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Étudiant</title>
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
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .nav-link.active { background: #ede9fe; color: #7c3aed; font-weight: 500; }
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
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-info { text-align: left; }
        .user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .user-role { font-size: 0.75rem; color: #64748b; }
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .stat-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-4px); }
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .stat-label { font-size: 0.875rem; color: #64748b; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.blue { background: #dbeafe; color: #0284c7; }
        .stat-icon.green { background: #d1fae5; color: #10b981; }
        .stat-icon.orange { background: #fed7aa; color: #ea580c; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
        .stat-sublabel { font-size: 0.875rem; color: #64748b; }
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; }
        .section-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
        .section-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
        .section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        .project-info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
        .info-item { padding: 1rem; background: #f8fafc; border-radius: 8px; }
        .info-label { font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem; }
        .info-value { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .timeline { position: relative; padding-left: 2rem; }
        .timeline::before { content: ''; position: absolute; left: 0.5rem; top: 0; bottom: 0; width: 2px; background: #e2e8f0; }
        .timeline-item { position: relative; margin-bottom: 2rem; }
        .timeline-marker { position: absolute; left: -1.5rem; width: 24px; height: 24px; background: white; border: 2px solid #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .timeline-marker.completed { background: #10b981; border-color: #10b981; color: white; }
        .timeline-marker.current { background: #0284c7; border-color: #0284c7; color: white; animation: pulse 2s infinite; }
        .timeline-content h4 { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.25rem; }
        .timeline-content p { font-size: 0.75rem; color: #64748b; margin: 0; }
        @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7); } 50% { box-shadow: 0 0 0 10px rgba(2, 132, 199, 0); } }
        .action-btn { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.75rem; }
        .action-btn:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); color: #0f172a; }
        .action-btn i { font-size: 1.25rem; color: #7c3aed; }
        .date-card { padding: 1rem; background: #f8fafc; border-radius: 8px; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; }
        .date-label { font-size: 0.875rem; color: #64748b; }
        .date-value { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .badge-status { padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.875rem; font-weight: 600; display: inline-block; }
        .badge-status.inscrit { background: #e0e7ff; color: #3730a3; }
        .badge-status.en_cours { background: #dbeafe; color: #1e40af; }
        .badge-status.rapport_soumis { background: #fef3c7; color: #92400e; }
        .badge-status.valide { background: #d1fae5; color: #065f46; }
        .badge-status.planifie { background: #e0e7ff; color: #4338ca; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="sidebar">
            <a href="/Soutenances_PFE/dashboards/etudiant.php" class="brand">
                <div class="brand-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
            </a>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/Soutenances_PFE/dashboards/etudiant.php" class="nav-link active">
                        <i class="bi bi-house-door"></i>
                        <span>Tableau de bord</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/projets/liste.php" class="nav-link">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Mon Projet</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/projets/upload_rapport.php" class="nav-link">
                        <i class="bi bi-cloud-upload"></i>
                        <span>Soumettre rapport</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/projets/messagerie.php" class="nav-link">
                        <i class="bi bi-chat-dots"></i>
                        <span>Messagerie</span>
                        <?php if ($nb_messages > 0): ?>
                        <span style="margin-left: auto; background: #ef4444; color: white; font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 12px; font-weight: 600;"><?= $nb_messages ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/planning/ma-soutenance.php" class="nav-link">
                        <i class="bi bi-calendar-event"></i>
                        <span>Ma soutenance</span>
                    </a>
                </li>
            </ul>
            
            <div class="nav-footer">
                <a href="/Soutenances_PFE/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Mon Projet de Fin d'Études</h1>
                    <p class="page-subtitle">Année universitaire <?= nettoyer($annee_uni) ?></p>
                </div>
                <div class="header-actions">
                    <?php if ($nb_messages > 0): ?>
                    <div class="notification-btn">
                        <i class="bi bi-chat-dots"></i>
                        <span class="notification-badge"><?= $nb_messages ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="user-menu">
                        <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                        <div class="user-info">
                            <div class="user-name"><?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div>
                            <div class="user-role">Étudiant</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Statut du Projet</div>
                        <div class="stat-icon purple"><i class="bi bi-clipboard-check"></i></div>
                    </div>
                    <div class="stat-value" style="font-size: 1.25rem;">
                        <?php if ($projet): ?>
                            <span class="badge-status <?= nettoyer($projet['statut']) ?>">
                                <?= nettoyer($statut_fr[$projet['statut']] ?? $projet['statut']) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge-status inscrit">Non inscrit</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-sublabel">État actuel</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Jours Restants</div>
                        <div class="stat-icon orange"><i class="bi bi-clock-history"></i></div>
                    </div>
                    <div class="stat-value"><?= $jours_restants ?></div>
                    <div class="stat-sublabel">Jusqu'à la <?= $soutenance ? 'soutenance' : 'date limite' ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Documents</div>
                        <div class="stat-icon blue"><i class="bi bi-file-earmark-pdf"></i></div>
                    </div>
                    <div class="stat-value"><?= $nb_documents ?></div>
                    <div class="stat-sublabel">Rapports soumis</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Messages</div>
                        <div class="stat-icon green"><i class="bi bi-chat-dots"></i></div>
                    </div>
                    <div class="stat-value"><?= $nb_messages ?></div>
                    <div class="stat-sublabel">Non lus</div>
                </div>
            </div>
            
            <div class="content-grid">
                <div>
                    <?php if ($projet): ?>
                    <div class="section-card">
                        <h3 class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Informations du projet
                        </h3>
                        
                        <h4 style="font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem;">
                            <?= nettoyer($projet['titre']) ?>
                        </h4>
                        
                        <div class="project-info-grid">
                            <?php if (!empty($projet['encadrant_nom'])): ?>
                            <div class="info-item">
                                <div class="info-label">Encadrant</div>
                                <div class="info-value"><?= nettoyer($projet['encadrant_nom']) ?></div>
                                <?php if (!empty($projet['encadrant_email'])): ?>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                                    <i class="bi bi-envelope"></i> <?= nettoyer($projet['encadrant_email']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($projet['binome_nom'])): ?>
                            <div class="info-item">
                                <div class="info-label">Binôme</div>
                                <div class="info-value"><?= nettoyer($projet['binome_nom']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-item">
                                <div class="info-label">Filière</div>
                                <div class="info-value"><?= nettoyer($projet['filiere_nom'] ?? '—') ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Date d'inscription</div>
                                <div class="info-value"><?= isset($projet['date_inscription']) ? formater_date($projet['date_inscription'], 'd M Y') : '—' ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-card">
                        <h3 class="section-title">
                            <i class="bi bi-list-check"></i>
                            Progression du projet
                        </h3>
                        
                        <div class="timeline">
                            <div class="timeline-item">
                                <div class="timeline-marker completed">
                                    <i class="bi bi-check" style="font-size: 0.75rem;"></i>
                                </div>
                                <div class="timeline-content">
                                    <h4>Inscription au PFE</h4>
                                    <p><?= isset($projet['date_inscription']) ? formater_date($projet['date_inscription'], 'd M Y') : '—' ?></p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-marker <?= !empty($projet['encadrant_nom']) ? 'completed' : 'current' ?>">
                                    <?php if (!empty($projet['encadrant_nom'])): ?>
                                    <i class="bi bi-check" style="font-size: 0.75rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h4>Encadrant affecté</h4>
                                    <p><?= !empty($projet['encadrant_nom']) ? nettoyer($projet['encadrant_nom']) : 'En attente' ?></p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-marker <?= ($nb_documents > 0) ? 'completed' : '' ?>">
                                    <?php if ($nb_documents > 0): ?>
                                    <i class="bi bi-check" style="font-size: 0.75rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h4>Rapport soumis</h4>
                                    <p><?= $nb_documents > 0 ? ($nb_documents . " version(s)") : 'Non soumis' ?></p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-marker <?= in_array($projet['statut'], ['valide_encadrant','planifie','planifiee','soutenu']) ? 'completed' : '' ?>">
                                    <?php if (in_array($projet['statut'], ['valide_encadrant','planifie','planifiee','soutenu'])): ?>
                                    <i class="bi bi-check" style="font-size: 0.75rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h4>Validation encadrant</h4>
                                    <p><?= in_array($projet['statut'], ['valide_encadrant','planifie','planifiee','soutenu']) ? 'Validé' : 'En attente' ?></p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-marker <?= $soutenance ? 'completed' : '' ?>">
                                    <?php if ($soutenance): ?>
                                    <i class="bi bi-check" style="font-size: 0.75rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h4>Soutenance planifiée</h4>
                                    <p><?= $soutenance ? formater_date($soutenance['date_soutenance'], 'd M Y H:i') : 'Non planifiée' ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="section-card" style="text-align: center; padding: 3rem;">
                        <i class="bi bi-inbox" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                        <h3 style="font-size: 1.25rem; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem;">Aucun projet inscrit</h3>
                        <p style="color: #64748b; margin-bottom: 1.5rem;">Vous n'avez pas encore inscrit de projet PFE</p>
                        <a href="/Soutenances_PFE/projets/inscription.php" class="btn btn-primary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 0.75rem 1.5rem; border-radius: 8px; color: white; text-decoration: none; display: inline-block;">
                            <i class="bi bi-plus-circle"></i> Inscrire un projet
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <div class="section-card">
                        <h3 class="section-title" style="font-size: 1rem;">
                            <i class="bi bi-lightning-charge"></i>
                            Actions rapides
                        </h3>
                        
                        <a href="/Soutenances_PFE/projets/upload_rapport.php" class="action-btn">
                            <i class="bi bi-cloud-upload"></i>
                            <span>Soumettre le rapport</span>
                        </a>
                        
                        <a href="/Soutenances_PFE/projets/messagerie.php" class="action-btn">
                            <i class="bi bi-chat-dots"></i>
                            <span>Envoyer un message</span>
                        </a>
                        
                        <a href="/Soutenances_PFE/projets/liste.php" class="action-btn">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>Voir mon projet</span>
                        </a>
                    </div>
                    
                    <div class="section-card">
                        <h3 class="section-title" style="font-size: 1rem;">
                            <i class="bi bi-calendar-event"></i>
                            Dates importantes
                        </h3>
                        
                        <div class="date-card">
                            <span class="date-label">Date limite rapport</span>
                            <span class="date-value"><?= formater_date($date_limite_rapport, 'd M Y') ?></span>
                        </div>
                        
                        <?php if ($soutenance): ?>
                        <div class="date-card" style="background: #ede9fe; border: 1px solid #c4b5fd;">
                            <div>
                                <div class="date-label" style="color: #5b21b6;">Ma soutenance</div>
                                <div class="date-value" style="color: #5b21b6; font-size: 1rem;">
                                    <?= formater_date($soutenance['date_soutenance'], 'd M Y') ?><br>
                                    <small><?= !empty($soutenance['heure_debut']) ? date('H:i', strtotime($soutenance['heure_debut'])) : '' ?></small>
                                </div>
                            </div>
                            <i class="bi bi-calendar-check" style="font-size: 1.5rem; color: #7c3aed;"></i>
                        </div>
                        
                        <div class="date-card">
                            <span class="date-label">Salle</span>
                            <span class="date-value"><?= nettoyer($soutenance['salle_nom']) ?><?= !empty($soutenance['batiment']) ? ' - ' . nettoyer($soutenance['batiment']) : '' ?></span>
                        </div>
                        <?php else: ?>
                        <div class="date-card">
                            <span class="date-label">Période de soutenance</span>
                            <span class="date-value">1-15 Fév 2025</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>