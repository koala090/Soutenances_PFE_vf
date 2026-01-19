<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'professeur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// --- Projets encadrés --
$stmt = $pdo->prepare("
    SELECT p.*,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(b.prenom, ' ', b.nom) as binome_nom,
           f.nom as filiere_nom
    FROM projets p
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs b ON p.binome_id = b.id
    JOIN filieres f ON p.filiere_id = f.id
    WHERE p.encadrant_id = ?
      AND p.annee_universitaire = '2024-2025'
    ORDER BY 
        CASE 
            WHEN p.statut = 'rapport_soumis' THEN 1
            WHEN p.statut = 'en_cours' THEN 2
            WHEN p.statut = 'planifie' THEN 3
            ELSE 4
        END,
        p.date_inscription DESC
");
$stmt->execute([$user['id']]);
$projets_encadres = $stmt->fetchAll();

// --- Jurys à venir ---
$stmt = $pdo->prepare("
    SELECT s.*, j.role_jury,
           p.titre as projet_titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           sa.nom as salle_nom, sa.batiment
    FROM jurys j
    JOIN soutenances s ON j.soutenance_id = s.id
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE j.professeur_id = ?
      AND s.date_soutenance >= CURDATE()
      AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.date_soutenance, s.heure_debut
    LIMIT 5
");
$stmt->execute([$user['id']]);
$mes_jurys = $stmt->fetchAll();

// --- Rapports à valider ---
$stmt = $pdo->prepare("
    SELECT r.*, p.titre as projet_titre, p.id as projet_id,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom
    FROM rapports r
    JOIN projets p ON r.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    WHERE p.encadrant_id = ?
      AND r.valide_encadrant = 0
    ORDER BY r.date_upload DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$rapports_a_valider = $stmt->fetchAll();

// --- Disponibilités ---
$stmt = $pdo->prepare("
    SELECT COUNT(*) as nb
    FROM disponibilites d
    JOIN periodes_disponibilite pd ON d.periode_id = pd.id
    WHERE d.professeur_id = ?
      AND pd.date_fin_saisie >= CURDATE()
");
$stmt->execute([$user['id']]);
$nb_disponibilites = $stmt->fetch()['nb'];

// --- Statistiques ---
$nb_projets = count($projets_encadres);
$nb_jurys = count($mes_jurys);
$nb_rapports = count($rapports_a_valider);

$statut_fr = [
    'inscrit' => 'Inscrit',
    'encadrant_affecte' => 'En cours',
    'en_cours' => 'En cours',
    'rapport_soumis' => 'Rapport soumis',
    'valide_encadrant' => 'Validé',
    'planifie' => 'Planifié',
    'soutenu' => 'Soutenu'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Professeur</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
/* --- GARDER TON DESIGN --- */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
.container-fluid { display: flex; min-height: 100vh; padding: 0; }
.sidebar { width: 240px; background: white; padding: 1.5rem; box-shadow: 2px 0 8px rgba(0,0,0,0.05); position: fixed; height: 100vh; overflow-y: auto; overflow-x: hidden; }
.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
.sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; text-decoration: none; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
.brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
.nav-menu { list-style: none; padding: 0; }
.nav-item { margin-bottom: 0.25rem; }
.nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
.nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
.nav-link.active { background: #d1fae5; color: #065f46; font-weight: 500; }
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
.user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
.user-info { text-align: left; }
.user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
.user-role { font-size: 0.75rem; color: #64748b; }
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
.stat-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-4px); }
.stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
.stat-label { font-size: 0.875rem; color: #64748b; }
.stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.stat-icon.green { background: #d1fae5; color: #10b981; }
.stat-icon.blue { background: #dbeafe; color: #0284c7; }
.stat-icon.orange { background: #fed7aa; color: #ea580c; }
.stat-icon.purple { background: #ede9fe; color: #7c3aed; }
.stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
.stat-sublabel { font-size: 0.875rem; color: #64748b; }
.content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; }
.section-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
.section-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
.section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
.project-list { display: flex; flex-direction: column; gap: 1rem; }
.project-item { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; transition: all 0.2s; }
.project-item:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); }
.project-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.25rem; }
.project-title { font-size: 0.75rem; color: #64748b; }
.badge-status { padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
.badge-status.en_cours { background: #dbeafe; color: #1e40af; }
.badge-status.rapport_soumis { background: #fef3c7; color: #92400e; }
.badge-status.valide { background: #d1fae5; color: #065f46; }
.jury-item { padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 0.75rem; }
.jury-date { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; }
.jury-info { font-size: 0.75rem; color: #64748b; }
.action-btn { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.75rem; }
.action-btn:hover { background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); color: #0f172a; }
.action-btn i { font-size: 1.25rem; color: #10b981; }
</style>
</head>
<body>
<div class="container-fluid">
    <div class="sidebar">
        <a href="/Soutenances_PFE/dashboards/professeur.php" class="brand">
            <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
        </a>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="/Soutenances_PFE/dashboards/professeur.php" class="nav-link active">
                    <i class="bi bi-house-door"></i><span>Tableau de bord</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/Soutenances_PFE/projets/liste.php?mode=encadre" class="nav-link">
                    <i class="bi bi-folder"></i><span>Mes projets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/Soutenances_PFE/projets/valider_rapport.php" class="nav-link">
                    <i class="bi bi-check-circle"></i><span>Valider rapports</span>
                    <?php if ($nb_rapports > 0): ?>
                    <span style="margin-left: auto; background: #ef4444; color: white; font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 12px; font-weight: 600;"><?= $nb_rapports ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="/Soutenances_PFE/planning/saisir_disponibilites.php" class="nav-link">
                    <i class="bi bi-calendar-plus"></i><span>Mes disponibilités</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/Soutenances_PFE/jurys/mes-jurys.php" class="nav-link">
                    <i class="bi bi-people"></i><span>Mes jurys</span>
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
                <h1 class="page-title">Tableau de bord Professeur</h1>
                <p class="page-subtitle">Vue d'ensemble de vos activités</p>
            </div>
            <div class="header-actions">
                <?php if ($nb_rapports > 0): ?>
                <div class="notification-btn">
                    <i class="bi bi-file-earmark-check"></i>
                    <span class="notification-badge"><?= $nb_rapports ?></span>
                </div>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div>
                        <div class="user-role">Professeur</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistiques -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-label">Projets Encadrés</div>
                    <div class="stat-icon green"><i class="bi bi-folder"></i></div>
                </div>
                <div class="stat-value"><?= $nb_projets ?></div>
                <div class="stat-sublabel">Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-label">Rapports à valider</div>
                    <div class="stat-icon orange"><i class="bi bi-check-circle"></i></div>
                </div>
                <div class="stat-value"><?= $nb_rapports ?></div>
                <div class="stat-sublabel">En attente</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-label">Mes jurys</div>
                    <div class="stat-icon blue"><i class="bi bi-people"></i></div>
                </div>
                <div class="stat-value"><?= $nb_jurys ?></div>
                <div class="stat-sublabel">À venir</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-label">Disponibilités saisies</div>
                    <div class="stat-icon purple"><i class="bi bi-calendar-plus"></i></div>
                </div>
                <div class="stat-value"><?= $nb_disponibilites ?></div>
                <div class="stat-sublabel">Semaine</div>
            </div>
        </div>
        
        <!-- Contenu -->
        <div class="content-grid">
            <div>
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-folder"></i> Projets encadrés</div>
                    <div class="project-list">
                        <?php if(count($projets_encadres) > 0): ?>
                            <?php foreach($projets_encadres as $projet): ?>
                                <div class="project-item">
                                    <div class="project-name"><?= htmlspecialchars($projet['titre']) ?></div>
                                    <div class="project-title">
                                        Étudiant: <?= htmlspecialchars($projet['etudiant_nom']) ?>
                                        <?php if($projet['binome_nom']): ?> | Binôme: <?= htmlspecialchars($projet['binome_nom']) ?><?php endif; ?><br>
                                        Filière: <?= htmlspecialchars($projet['filiere_nom']) ?><br>
                                        <span class="badge-status <?= $projet['statut'] ?>"><?= $statut_fr[$projet['statut']] ?? $projet['statut'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="project-item text-center text-muted" style="font-style:italic;">Pas encore de projet encadré</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-title"><i class="bi bi-file-earmark-check"></i> Rapports à valider</div>
                    <div class="project-list">
                        <?php if(count($rapports_a_valider) > 0): ?>
                            <?php foreach($rapports_a_valider as $rapport): ?>
                                <a href="/Soutenances_PFE/projets/valider_rapport.php?id=<?= (int)$rapport['id'] ?>" class="action-btn">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <div>
                                        <div><?= htmlspecialchars($rapport['projet_titre']) ?></div>
                                        <small><?= htmlspecialchars($rapport['etudiant_nom']) ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="project-item text-center text-muted" style="font-style:italic;">Pas encore de rapport à valider</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div>
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-people"></i> Mes jurys à venir</div>
                    <?php if(count($mes_jurys) > 0): ?>
                        <?php foreach($mes_jurys as $jury): ?>
                        <div class="jury-item">
                            <div class="jury-date"><?= date('d/m/Y H:i', strtotime($jury['date_soutenance'].' '.$jury['heure_debut'])) ?></div>
                            <div class="jury-info">
                                Projet: <?= htmlspecialchars($jury['projet_titre']) ?><br>
                                Étudiant: <?= htmlspecialchars($jury['etudiant_nom']) ?><br>
                                Salle: <?= htmlspecialchars($jury['salle_nom'].' ('.$jury['batiment'].')') ?><br>
                                Rôle: <?= htmlspecialchars(ucfirst($jury['role_jury'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="jury-item text-center text-muted" style="font-style:italic;">Pas encore de jury prévu</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
