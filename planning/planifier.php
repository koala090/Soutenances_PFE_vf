<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'coordinateur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Récupérer les salles
$stmt = $pdo->query("SELECT * FROM salles WHERE disponible = 1 ORDER BY nom");
$salles = $stmt->fetchAll();

// Récupérer les projets prêts à être planifiés
$stmt = $pdo->prepare("
    SELECT p.*, 
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           enc.id as encadrant_id
    FROM projets p
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    LEFT JOIN soutenances s ON p.id = s.projet_id
    WHERE p.filiere_id = ? AND p.encadrant_id IS NOT NULL AND s.id IS NULL
    ORDER BY p.date_inscription
");
$stmt->execute([$user['filiere_id']]);
$projets_a_planifier = $stmt->fetchAll();

// Récupérer les soutenances déjà planifiées
$stmt = $pdo->prepare("
    SELECT s.*, 
           p.titre as projet_titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           sa.nom as salle_nom,
           p.encadrant_id
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE p.filiere_id = ?
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute([$user['filiere_id']]);
$soutenances_planifiees = $stmt->fetchAll();

// Récupérer les disponibilités des professeurs
$stmt = $pdo->prepare("
    SELECT d.*, CONCAT(u.prenom, ' ', u.nom) as prof_nom
    FROM disponibilites d
    JOIN utilisateurs u ON d.professeur_id = u.id
    WHERE u.filiere_id = ?
    ORDER BY d.date_disponible, d.heure_debut
");
$stmt->execute([$user['filiere_id']]);
$disponibilites = $stmt->fetchAll();

// Traitement AJAX pour planifier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'planifier') {
        $projet_id = $_POST['projet_id'];
        $salle_id = $_POST['salle_id'];
        $date_soutenance = $_POST['date_soutenance'];
        $heure_debut = $_POST['heure_debut'];
        
        // Calculer heure de fin (durée standard: 75 minutes)
        $heure_fin = date('H:i:s', strtotime($heure_debut) + 75*60);
        
        try {
            // Vérifier les conflits de salle
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM soutenances 
                WHERE salle_id = ? 
                AND date_soutenance = ? 
                AND (
                    (heure_debut <= ? AND heure_fin > ?) OR
                    (heure_debut < ? AND heure_fin >= ?) OR
                    (heure_debut >= ? AND heure_fin <= ?)
                )
            ");
            $stmt->execute([$salle_id, $date_soutenance, $heure_debut, $heure_debut, $heure_fin, $heure_fin, $heure_debut, $heure_fin]);
            
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Conflit: La salle est déjà occupée à cet horaire']);
                exit;
            }
            
            // Vérifier les conflits d'encadrant
            $stmt = $pdo->prepare("SELECT encadrant_id FROM projets WHERE id = ?");
            $stmt->execute([$projet_id]);
            $encadrant_id = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM soutenances s
                JOIN projets p ON s.projet_id = p.id
                WHERE p.encadrant_id = ? 
                AND s.date_soutenance = ? 
                AND (
                    (s.heure_debut <= ? AND s.heure_fin > ?) OR
                    (s.heure_debut < ? AND s.heure_fin >= ?) OR
                    (s.heure_debut >= ? AND s.heure_fin <= ?)
                )
            ");
            $stmt->execute([$encadrant_id, $date_soutenance, $heure_debut, $heure_debut, $heure_fin, $heure_fin, $heure_debut, $heure_fin]);
            
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Conflit: L\'encadrant a déjà une soutenance à cet horaire']);
                exit;
            }
            
            // Insérer la soutenance
            $stmt = $pdo->prepare("
                INSERT INTO soutenances 
                (projet_id, salle_id, date_soutenance, heure_debut, heure_fin, statut)
                VALUES (?, ?, ?, ?, ?, 'planifiee')
            ");
            $stmt->execute([$projet_id, $salle_id, $date_soutenance, $heure_debut, $heure_fin]);
            
            // Mettre à jour le statut du projet
            $stmt = $pdo->prepare("UPDATE projets SET statut = 'planifie' WHERE id = ?");
            $stmt->execute([$projet_id]);
            
            echo json_encode(['success' => true, 'message' => 'Soutenance planifiée avec succès']);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
            exit;
        }
    }
    
    if ($_POST['action'] === 'supprimer') {
        try {
            $soutenance_id = $_POST['soutenance_id'];
            
            // Récupérer le projet_id avant suppression
            $stmt = $pdo->prepare("SELECT projet_id FROM soutenances WHERE id = ?");
            $stmt->execute([$soutenance_id]);
            $projet_id = $stmt->fetchColumn();
            
            // Supprimer la soutenance
            $stmt = $pdo->prepare("DELETE FROM soutenances WHERE id = ?");
            $stmt->execute([$soutenance_id]);
            
            // Remettre le projet en attente
            $stmt = $pdo->prepare("UPDATE projets SET statut = 'encadrant_affecte' WHERE id = ?");
            $stmt->execute([$projet_id]);
            
            echo json_encode(['success' => true, 'message' => 'Soutenance supprimée']);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planification Manuelle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
        
        .planning-container { display: flex; gap: 1.5rem; padding: 2rem; min-height: 100vh; }
        
        /* Sidebar gauche - Projets à planifier */
        .projets-sidebar {
            width: 320px;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            max-height: calc(100vh - 4rem);
            overflow-y: auto;
            position: sticky;
            top: 2rem;
        }
        
        .sidebar-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .projet-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: move;
            transition: all 0.2s;
        }
        
        .projet-item:hover {
            border-color: #0891b2;
            box-shadow: 0 4px 8px rgba(8,145,178,0.2);
            transform: translateY(-2px);
        }
        
        .projet-item.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }
        
        .projet-etudiant {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }
        
        .projet-titre {
            font-size: 0.8125rem;
            color: #64748b;
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .projet-encadrant {
            font-size: 0.75rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        /* Zone centrale - Calendrier */
        .calendar-main {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .calendar-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .calendar-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .btn-today {
            padding: 0.5rem 1rem;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-today:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .week-navigation {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-btn {
            width: 36px;
            height: 36px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .nav-btn:hover {
            background: #f8fafc;
            border-color: #0891b2;
            color: #0891b2;
        }
        
        .current-week {
            font-size: 0.875rem;
            color: #64748b;
            min-width: 200px;
            text-align: center;
        }
        
        /* Grille du calendrier */
        .calendar-grid {
            display: grid;
            grid-template-columns: 80px repeat(5, 1fr);
            gap: 1px;
            background: #e2e8f0;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .time-header,
        .day-header {
            background: #f8fafc;
            padding: 1rem;
            font-weight: 600;
            color: #0f172a;
            font-size: 0.875rem;
            text-align: center;
        }
        
        .time-slot {
            background: #fafbfc;
            padding: 0.75rem 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
            text-align: center;
            border-right: 1px solid #e2e8f0;
        }
        
        .calendar-cell {
            background: white;
            min-height: 100px;
            padding: 0.5rem;
            position: relative;
            transition: all 0.2s;
        }
        
        .calendar-cell:hover {
            background: #f0f9ff;
        }
        
        .calendar-cell.drop-target {
            background: #dbeafe;
            border: 2px dashed #0891b2;
        }
        
        .soutenance-card {
            background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);
            color: white;
            padding: 0.75rem;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            font-size: 0.8125rem;
            box-shadow: 0 2px 4px rgba(8,145,178,0.3);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .soutenance-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(8,145,178,0.4);
        }
        
        .soutenance-etudiant {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .soutenance-info {
            font-size: 0.75rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .soutenance-delete {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: all 0.2s;
        }
        
        .soutenance-card:hover .soutenance-delete {
            opacity: 1;
        }
        
        .soutenance-delete:hover {
            background: rgba(239,68,68,0.9);
        }
        
        /* Sidebar droite - Informations */
        .info-sidebar {
            width: 280px;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            max-height: calc(100vh - 4rem);
            overflow-y: auto;
            position: sticky;
            top: 2rem;
        }
        
        .legend-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 1rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 0.8125rem;
            color: #64748b;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .stats-section {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.8125rem;
        }
        
        .stat-label {
            color: #64748b;
        }
        
        .stat-value {
            font-weight: 600;
            color: #0f172a;
        }
        
        /* Modal */
        .modal-backdrop.show {
            opacity: 0.5;
        }
        
        /* Salles filter */
        .salles-filter {
            margin-bottom: 1.5rem;
        }
        
        .salle-chip {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.8125rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .salle-chip:hover {
            background: #e2e8f0;
        }
        
        .salle-chip.active {
            background: #0891b2;
            color: white;
            border-color: #0891b2;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }
    </style>
</head>
<body>
    <div class="planning-container">
        <!-- Sidebar Gauche: Projets à planifier -->
        <div class="projets-sidebar">
            <div class="sidebar-title">
                <i class="bi bi-folder"></i>
                Projets à planifier (<?= count($projets_a_planifier) ?>)
            </div>
            
            <?php if (count($projets_a_planifier) > 0): ?>
                <?php foreach ($projets_a_planifier as $projet): ?>
                <div class="projet-item" draggable="true" 
                     data-projet-id="<?= $projet['id'] ?>"
                     data-etudiant="<?= htmlspecialchars($projet['etudiant_nom']) ?>"
                     data-encadrant-id="<?= $projet['encadrant_id'] ?>">
                    <div class="projet-etudiant"><?= htmlspecialchars($projet['etudiant_nom']) ?></div>
                    <div class="projet-titre"><?= htmlspecialchars(substr($projet['titre'], 0, 60)) ?>...</div>
                    <div class="projet-encadrant">
                        <i class="bi bi-person"></i>
                        <?= htmlspecialchars($projet['encadrant_nom']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle"></i>
                    <p>Tous les projets sont planifiés !</p>
                </div>
            <?php endif; ?>
            
            <div class="mt-3">
                <a href="planifier_auto.php" class="btn btn-sm btn-success w-100">
                    <i class="bi bi-lightning-charge"></i> Planification Auto
                </a>
            </div>
        </div>
        
        <!-- Zone Centrale: Calendrier -->
        <div class="calendar-main">
            <div class="calendar-header">
                <h1 class="calendar-title">
                    <i class="bi bi-calendar-week"></i> Planning Manuel
                </h1>
                <div class="calendar-actions">
                    <button class="btn-today" onclick="goToToday()">
                        <i class="bi bi-calendar-day"></i> Aujourd'hui
                    </button>
                    <div class="week-navigation">
                        <button class="nav-btn" onclick="previousWeek()">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="current-week" id="currentWeekLabel"></div>
                        <button class="nav-btn" onclick="nextWeek()">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filtres par salle -->
            <div class="salles-filter">
                <strong style="font-size: 0.875rem; color: #64748b; margin-right: 0.5rem;">Salles:</strong>
                <span class="salle-chip active" data-salle-id="all">Toutes</span>
                <?php foreach ($salles as $salle): ?>
                <span class="salle-chip" data-salle-id="<?= $salle['id'] ?>">
                    <?= htmlspecialchars($salle['nom']) ?>
                </span>
                <?php endforeach; ?>
            </div>
            
            <!-- Grille du calendrier -->
            <div class="calendar-grid" id="calendarGrid">
                <!-- Sera généré par JavaScript -->
            </div>
        </div>
        
        <!-- Sidebar Droite: Informations -->
        <div class="info-sidebar">
            <div class="legend-title">
                <i class="bi bi-info-circle"></i> Légende
            </div>
            
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);"></div>
                <span>Soutenance planifiée</span>
            </div>
            
            <div class="legend-item">
                <div class="legend-color" style="background: #f0f9ff; border: 2px dashed #0891b2;"></div>
                <span>Zone de dépôt</span>
            </div>
            
            <div class="stats-section">
                <div class="legend-title">Statistiques</div>
                
                <div class="stat-item">
                    <span class="stat-label">Projets à planifier</span>
                    <span class="stat-value"><?= count($projets_a_planifier) ?></span>
                </div>
                
                <div class="stat-item">
                    <span class="stat-label">Soutenances planifiées</span>
                    <span class="stat-value"><?= count($soutenances_planifiees) ?></span>
                </div>
                
                <div class="stat-item">
                    <span class="stat-label">Salles disponibles</span>
                    <span class="stat-value"><?= count($salles) ?></span>
                </div>
            </div>
            
            <div class="stats-section">
                <div class="legend-title">Instructions</div>
                <p style="font-size: 0.8125rem; color: #64748b; line-height: 1.6;">
                    <strong>1.</strong> Glissez un projet depuis la liste<br>
                    <strong>2.</strong> Déposez-le sur un créneau horaire<br>
                    <strong>3.</strong> Choisissez la salle<br>
                    <strong>4.</strong> Validez la planification
                </p>
            </div>
            
            <a href="../dashboards/coordinateur.php" class="btn btn-secondary w-100 mt-3">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
    
    <!-- Modal de planification -->
    <div class="modal fade" id="planificationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Planifier la soutenance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Étudiant</label>
                        <input type="text" class="form-control" id="modalEtudiant" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" id="modalDate" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Heure de début</label>
                        <select class="form-select" id="modalHeure" required>
                            <option value="08:00:00">08:00</option>
                            <option value="09:00:00">09:00</option>
                            <option value="10:00:00">10:00</option>
                            <option value="11:00:00">11:00</option>
                            <option value="12:00:00">12:00</option>
                            <option value="13:00:00">13:00</option>
                            <option value="14:00:00">14:00</option>
                            <option value="15:00:00">15:00</option>
                            <option value="16:00:00">16:00</option>
                            <option value="17:00:00">17:00</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Salle</label>
                        <select class="form-select" id="modalSalle" required>
                            <option value="">-- Choisir une salle --</option>
                            <?php foreach ($salles as $salle): ?>
                            <option value="<?= $salle['id'] ?>">
                                <?= htmlspecialchars($salle['nom']) ?> (Capacité: <?= $salle['capacite'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-info" style="font-size: 0.875rem;">
                        <i class="bi bi-info-circle"></i> Durée: 1h15 (présentation + questions + délibération)
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" onclick="confirmerPlanification()">
                        <i class="bi bi-check-circle"></i> Confirmer
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Données PHP vers JS
        const soutenancesData = <?= json_encode($soutenances_planifiees) ?>;
        const disponibilitesData = <?= json_encode($disponibilites) ?>;
        
        let currentWeekStart = new Date();
        currentWeekStart.setDate(currentWeekStart.getDate() - currentWeekStart.getDay() + 1); // Lundi
        
        let draggedProjet = null;
        let selectedCell = null;
        
        // Génération du calendrier
        function generateCalendar() {
            const grid = document.getElementById('calendarGrid');
            grid.innerHTML = '';
            
            // Headers
            const emptyHeader = document.createElement('div');
            emptyHeader.className = 'time-header';
            grid.appendChild(emptyHeader);
            
            const days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
            const dates = [];
            
            for (let i = 0; i < 5; i++) {
                const date = new Date(currentWeekStart);
                date.setDate(currentWeekStart.getDate() + i);
                dates.push(date);
                
                const dayHeader = document.createElement('div');
                dayHeader.className = 'day-header';
                dayHeader.innerHTML = `${days[i]}<br><small>${date.getDate()}/${date.getMonth() + 1}</small>`;
                grid.appendChild(dayHeader);
            }
            
            // Créneaux horaires (8h-18h)
            const hours = ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
            
            hours.forEach(hour => {
                // Colonne heure
                const timeSlot = document.createElement('div');
                timeSlot.className = 'time-slot';
                timeSlot.textContent = hour;
                grid.appendChild(timeSlot);
                
                // Cellules pour chaque jour
                dates.forEach((date, dayIndex) => {
                    const cell = document.createElement('div');
                    cell.className = 'calendar-cell';
                    cell.dataset.date = date.toISOString().split('T')[0];
                cell.dataset.hour = hour + ':00';
                
                // Ajouter les soutenances existantes
                const soutenances = soutenancesData.filter(s => {
                    return s.date_soutenance === cell.dataset.date && 
                           s.heure_debut.substring(0, 5) === hour;
                });
                
                soutenances.forEach(s => {
                    const card = document.createElement('div');
                    card.className = 'soutenance-card';
                    card.innerHTML = `
                        <button class="soutenance-delete" onclick="supprimerSoutenance(${s.id}, event)">
                            <i class="bi bi-x"></i>
                        </button>
                        <div class="soutenance-etudiant">${s.etudiant_nom}</div>
                        <div class="soutenance-info">
                            <i class="bi bi-clock"></i> ${s.heure_debut.substring(0, 5)} - ${s.heure_fin.substring(0, 5)}
                        </div>
                        <div class="soutenance-info">
                            <i class="bi bi-door-closed"></i> ${s.salle_nom}
                        </div>
                    `;
                    cell.appendChild(card);
                });
                
                // Drag & Drop
                cell.addEventListener('dragover', handleDragOver);
                cell.addEventListener('dragleave', handleDragLeave);
                cell.addEventListener('drop', handleDrop);
                
                grid.appendChild(cell);
            });
        });
        
        updateWeekLabel();
    }
    
    function updateWeekLabel() {
        const endDate = new Date(currentWeekStart);
        endDate.setDate(currentWeekStart.getDate() + 4);
        
        const label = `${currentWeekStart.getDate()}/${currentWeekStart.getMonth() + 1} - ${endDate.getDate()}/${endDate.getMonth() + 1}/${endDate.getFullYear()}`;
        document.getElementById('currentWeekLabel').textContent = label;
    }
    
    function previousWeek() {
        currentWeekStart.setDate(currentWeekStart.getDate() - 7);
        generateCalendar();
    }
    
    function nextWeek() {
        currentWeekStart.setDate(currentWeekStart.getDate() + 7);
        generateCalendar();
    }
    
    function goToToday() {
        currentWeekStart = new Date();
        currentWeekStart.setDate(currentWeekStart.getDate() - currentWeekStart.getDay() + 1);
        generateCalendar();
    }
    
    // Drag & Drop handlers
    document.querySelectorAll('.projet-item').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            item.classList.add('dragging');
            draggedProjet = {
                id: item.dataset.projetId,
                etudiant: item.dataset.etudiant,
                encadrantId: item.dataset.encadrantId
            };
        });
        
        item.addEventListener('dragend', (e) => {
            item.classList.remove('dragging');
        });
    });
    
    function handleDragOver(e) {
        e.preventDefault();
        e.currentTarget.classList.add('drop-target');
    }
    
    function handleDragLeave(e) {
        e.currentTarget.classList.remove('drop-target');
    }
    
    function handleDrop(e) {
        e.preventDefault();
        e.currentTarget.classList.remove('drop-target');
        
        if (!draggedProjet) return;
        
        selectedCell = {
            date: e.currentTarget.dataset.date,
            hour: e.currentTarget.dataset.hour
        };
        
        // Ouvrir le modal
        document.getElementById('modalEtudiant').value = draggedProjet.etudiant;
        document.getElementById('modalDate').value = selectedCell.date;
        document.getElementById('modalHeure').value = selectedCell.hour;
        
        const modal = new bootstrap.Modal(document.getElementById('planificationModal'));
        modal.show();
    }
    
    // Planification
    function confirmerPlanification() {
        const salle = document.getElementById('modalSalle').value;
        const heure = document.getElementById('modalHeure').value;
        
        if (!salle) {
            alert('Veuillez choisir une salle');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'planifier');
        formData.append('projet_id', draggedProjet.id);
        formData.append('salle_id', salle);
        formData.append('date_soutenance', selectedCell.date);
        formData.append('heure_debut', heure);
        
        fetch('planifier.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(err => {
            alert('Erreur de connexion');
            console.error(err);
        });
    }
    
    // Suppression
    function supprimerSoutenance(soutenanceId, event) {
        event.stopPropagation();
        
        if (!confirm('Êtes-vous sûr de vouloir supprimer cette soutenance ?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'supprimer');
        formData.append('soutenance_id', soutenanceId);
        
        fetch('planifier.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        });
    }
    
    // Filtrage par salle
    document.querySelectorAll('.salle-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.salle-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            
            const salleId = chip.dataset.salleId;
            // TODO: Implémenter le filtrage
        });
    });
    
    // Initialisation
    generateCalendar();
</script>
</body>
</html>
