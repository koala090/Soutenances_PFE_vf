<?php
// /Soutenances_PFE/planning/ma-soutenance.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
           bin.email as binome_email,
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



// Récupérer la soutenance
$soutenance = null;
$jury_membres = [];

if ($projet) {
    // 1) Soutenance + salle
    $stmt = $pdo->prepare("
        SELECT s.*,
               sa.nom AS salle_nom,
               sa.batiment,
               sa.capacite
        FROM soutenances s
        JOIN salles sa ON s.salle_id = sa.id
        WHERE s.projet_id = ?
        ORDER BY s.date_soutenance, s.heure_debut
        LIMIT 1
    ");
    $stmt->execute([$projet['id']]);
    $soutenance = $stmt->fetch();

    // 2) Jury via la table jurys
    if ($soutenance) {
        $stmt = $pdo->prepare("
            SELECT j.role_jury,
                   CONCAT(u.prenom, ' ', u.nom) AS nom,
                   u.email
            FROM jurys j
            JOIN utilisateurs u ON j.professeur_id = u.id
            WHERE j.soutenance_id = ?
            ORDER BY FIELD(j.role_jury, 'president', 'rapporteur', 'examinateur', 'encadrant', 'invite')
        ");
        $stmt->execute([$soutenance['id']]);
        $rows = $stmt->fetchAll();

        $labels = [
            'president'   => 'Président',
            'rapporteur'  => 'Rapporteur',
            'examinateur' => 'Examinateur',
            'encadrant'   => 'Encadrant',
            'invite'      => 'Invité'
        ];

        foreach ($rows as $r) {
            $jury_membres[] = [
                'role'  => $labels[$r['role_jury']] ?? $r['role_jury'],
                'nom'   => $r['nom'],
                'email' => $r['email']
            ];
        }
    }
}


// Calcul du temps restant
$temps_restant_str = '';
$jours_restants = 0;
if ($soutenance) {
    $sout_datetime = $soutenance['date_soutenance'];
    if (!empty($soutenance['heure_debut'])) {
        $sout_datetime .= ' ' . $soutenance['heure_debut'];
    } else {
        $sout_datetime .= ' 00:00';
    }
    $diff_seconds = strtotime($sout_datetime) - time();
    $jours_restants = (int) floor($diff_seconds / 86400);
    
    if ($jours_restants > 0) {
        if ($jours_restants == 1) {
            $temps_restant_str = "Demain";
        } else if ($jours_restants < 7) {
            $temps_restant_str = "Dans $jours_restants jours";
        } else if ($jours_restants < 30) {
            $semaines = floor($jours_restants / 7);
            $temps_restant_str = "Dans $semaines semaine" . ($semaines > 1 ? 's' : '');
        } else {
            $temps_restant_str = "Dans " . floor($jours_restants / 30) . " mois";
        }
    } else if ($jours_restants == 0) {
        $temps_restant_str = "Aujourd'hui";
    } else {
        $temps_restant_str = "Passée";
    }
}

// Messages non lus
$nb_messages = 0;
if ($projet) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messages 
            WHERE projet_id = ? 
            AND expediteur_id != ? 
            AND lu = 0
        ");
        $stmt->execute([$projet['id'], $user['id']]);
        $nb_messages = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $nb_messages = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ma Soutenance - PFE Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
        .container-fluid { display: flex; min-height: 100vh; padding: 0; }
        .sidebar { width: 240px; background: white; padding: 1.5rem; box-shadow: 2px 0 8px rgba(0,0,0,0.05); position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; text-decoration: none; }
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); }
        .nav-link.active { background: #ede9fe; color: #7c3aed; font-weight: 500; }
        .nav-link i { font-size: 1.125rem; }
        .nav-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
        .nav-footer .nav-link { color: #ef4444; }
        .nav-footer .nav-link:hover { background: #fee2e2; }
        .main-content { flex: 1; padding: 2rem; margin-left: 240px; width: 100%; max-width: 1200px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
        .page-subtitle { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        .user-menu { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; background: white; border: 1px solid #e2e8f0; border-radius: 10px; }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-info { text-align: left; }
        .user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .user-role { font-size: 0.75rem; color: #64748b; }
        
        .hero-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2.5rem; border-radius: 16px; margin-bottom: 2rem; position: relative; overflow: hidden; }
        .hero-card::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .hero-card::after { content: ''; position: absolute; bottom: -30%; left: -5%; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .hero-content { position: relative; z-index: 1; }
        .hero-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.9; }
        .hero-title { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem; }
        .hero-date { font-size: 2.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .hero-time { font-size: 1.25rem; opacity: 0.9; margin-bottom: 1rem; }
        .hero-countdown { display: inline-block; background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 600; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .info-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .info-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .info-card-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .info-card-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .icon-purple { background: #ede9fe; color: #7c3aed; }
        .icon-blue { background: #dbeafe; color: #0284c7; }
        .icon-green { background: #d1fae5; color: #10b981; }
        .icon-orange { background: #fed7aa; color: #ea580c; }
        .info-card-title { font-size: 1rem; font-weight: 600; color: #0f172a; }
        .info-item { padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
        .info-item:last-child { border-bottom: none; }
        .info-label { font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem; }
        .info-value { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        
        .jury-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; }
        .jury-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        .jury-member { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px; margin-bottom: 0.75rem; }
        .jury-member:last-child { margin-bottom: 0; }
        .jury-avatar { width: 48px; height: 48px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; }
        .jury-info { flex: 1; }
        .jury-role { font-size: 0.75rem; color: #64748b; margin-bottom: 0.125rem; }
        .jury-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.125rem; }
        .jury-email { font-size: 0.75rem; color: #64748b; }
        
        .alert-info { background: #dbeafe; border: 1px solid #bfdbfe; color: #1e40af; padding: 1rem; border-radius: 8px; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; }
        .alert-warning { background: #fef3c7; border: 1px solid #fde68a; color: #92400e; padding: 1rem; border-radius: 8px; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; }
        .alert-empty { text-align: center; padding: 4rem 2rem; }
        .alert-empty i { font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; }
        .alert-empty h3 { font-size: 1.25rem; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; }
        .alert-empty p { color: #64748b; margin-bottom: 1.5rem; }
        
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 0.75rem 1.5rem; border-radius: 8px; color: white; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Sidebar -->
        <div class="sidebar">
            <a href="/Soutenances_PFE/dashboards/etudiant.php" class="brand">
                <div class="brand-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
            </a>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/Soutenances_PFE/dashboards/etudiant.php" class="nav-link">
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
                    <a href="/Soutenances_PFE/planning/ma-soutenance.php" class="nav-link active">
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
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Ma Soutenance</h1>
                    <p class="page-subtitle">Détails de votre soutenance de PFE</p>
                </div>
                <div class="user-menu">
                    <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div>
                        <div class="user-role">Étudiant</div>
                    </div>
                </div>
            </div>
            
            <?php if (!$projet): ?>
                <div class="alert-empty">
                    <i class="bi bi-inbox"></i>
                    <h3>Aucun projet inscrit</h3>
                    <p>Vous devez d'abord inscrire un projet pour consulter votre soutenance</p>
                    <a href="/Soutenances_PFE/projets/inscription.php" class="btn-primary">
                        <i class="bi bi-plus-circle"></i> Inscrire un projet
                    </a>
                </div>
            <?php elseif (!$soutenance): ?>
                <div class="alert-warning">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem;"></i>
                    <div>
                        <strong>Soutenance non planifiée</strong><br>
                        <small>Votre soutenance n'a pas encore été planifiée par l'administration. Vous serez notifié dès qu'elle sera programmée.</small>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-card-icon icon-purple">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <h3 class="info-card-title">Informations du projet</h3>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Titre du projet</div>
                        <div class="info-value"><?= nettoyer($projet['titre']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Encadrant</div>
                        <div class="info-value"><?= nettoyer($projet['encadrant_nom'] ?? 'Non affecté') ?></div>
                    </div>
                    <?php if (!empty($projet['binome_nom'])): ?>
                    <div class="info-item">
                        <div class="info-label">Binôme</div>
                        <div class="info-value"><?= nettoyer($projet['binome_nom']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Hero Card -->
                <div class="hero-card">
                    <div class="hero-content">
                        <div class="hero-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="hero-title">Votre soutenance est planifiée</div>
                        <div class="hero-date"><?= formater_date($soutenance['date_soutenance'], 'd M Y') ?></div>
                        <div class="hero-time">
                            <?php if (!empty($soutenance['heure_debut'])): ?>
                                <?= date('H:i', strtotime($soutenance['heure_debut'])) ?>
                                <?php if (!empty($soutenance['heure_fin'])): ?>
                                    - <?= date('H:i', strtotime($soutenance['heure_fin'])) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($jours_restants >= 0): ?>
                        <div class="hero-countdown">
                            <i class="bi bi-clock"></i> <?= $temps_restant_str ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Info Grid -->
                <div class="info-grid">
                    <!-- Projet Info -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon icon-purple">
                                <i class="bi bi-file-earmark-text"></i>
                            </div>
                            <h3 class="info-card-title">Projet</h3>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Titre</div>
                            <div class="info-value"><?= nettoyer($projet['titre']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Filière</div>
                            <div class="info-value"><?= nettoyer($projet['filiere_nom'] ?? '—') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Encadrant</div>
                            <div class="info-value"><?= nettoyer($projet['encadrant_nom']) ?></div>
                        </div>
                        <?php if (!empty($projet['binome_nom'])): ?>
                        <div class="info-item">
                            <div class="info-label">Binôme</div>
                            <div class="info-value"><?= nettoyer($projet['binome_nom']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Salle Info -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon icon-blue">
                                <i class="bi bi-geo-alt"></i>
                            </div>
                            <h3 class="info-card-title">Lieu</h3>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Salle</div>
                            <div class="info-value"><?= nettoyer($soutenance['salle_nom']) ?></div>
                        </div>
                        <?php if (!empty($soutenance['batiment'])): ?>
                        <div class="info-item">
                            <div class="info-label">Bâtiment</div>
                            <div class="info-value"><?= nettoyer($soutenance['batiment']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($soutenance['capacite'])): ?>
                        <div class="info-item">
                            <div class="info-label">Capacité</div>
                            <div class="info-value"><?= nettoyer($soutenance['capacite']) ?> personnes</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Jury -->
                <?php if (!empty($jury_membres)): ?>
                <div class="jury-card">
                    <h3 class="jury-title">
                        <i class="bi bi-people"></i>
                        Membres du Jury
                    </h3>
                    
                    <?php foreach ($jury_membres as $membre): ?>
                    <div class="jury-member">
                        <div class="jury-avatar">
                            <?= strtoupper(substr($membre['nom'], 0, 2)) ?>
                        </div>
                        <div class="jury-info">
                            <div class="jury-role"><?= nettoyer($membre['role']) ?></div>
                            <div class="jury-name"><?= nettoyer($membre['nom']) ?></div>
                            <?php if (!empty($membre['email'])): ?>
                            <div class="jury-email">
                                <i class="bi bi-envelope"></i> <?= nettoyer($membre['email']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Conseils -->
                <div class="alert-info">
                    <i class="bi bi-info-circle-fill" style="font-size: 1.5rem;"></i>
                    <div>
                        <strong>Conseils pour votre soutenance</strong><br>
                        <small>Préparez une présentation de 15-20 minutes, arrivez 15 minutes en avance, et n'oubliez pas d'apporter votre rapport imprimé.</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>