<?php
session_start();
require_once '../config/database.php';

// Vérifier que l'utilisateur est connecté et est une assistante
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'assistante') {
    header('Location: ../login.php');
    exit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    $nom = trim($_POST['nom'] ?? '');
    $batiment = trim($_POST['batiment'] ?? '');
    $etage = trim($_POST['etage'] ?? '');
    $capacite = intval($_POST['capacite'] ?? 0);
    $disponible = isset($_POST['disponible']) ? 1 : 0;
    
    // Récupérer les équipements sélectionnés
    $equipements = $_POST['equipements'] ?? [];
    $equipements_json = json_encode($equipements);

    if (empty($nom)) {
        $errors[] = "Le nom de la salle est obligatoire";
    }
    if (empty($batiment)) {
        $errors[] = "Le bâtiment est obligatoire";
    }
    if ($capacite <= 0) {
        $errors[] = "La capacité doit être supérieure à 0";
    }

    // Vérifier si la salle existe déjà
    if (empty($errors)) {
        $check_query = "SELECT id FROM salles WHERE nom = ? AND batiment = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$nom, $batiment]);
        if ($check_stmt->fetch()) {
            $errors[] = "Une salle avec ce nom existe déjà dans ce bâtiment";
        }
    }

    // Insérer la salle
    if (empty($errors)) {
        try {
            $query = "INSERT INTO salles (nom, batiment, etage, capacite, equipements, disponible) 
                      VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$nom, $batiment, $etage, $capacite, $equipements_json, $disponible]);
            
            header('Location: liste.php?success=' . urlencode('Salle ajoutée avec succès'));
            exit();
        } catch (PDOException $e) {
            $errors[] = "Erreur lors de l'ajout de la salle: " . $e->getMessage();
        }
    }
}

$page_title = "Ajouter une Salle";
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: #667eea;
            font-size: 28px;
            margin-bottom: 5px;
        }

        .header p {
            color: #718096;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group label .required {
            color: #e53e3e;
        }

        .form-group input[type="text"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .equipements-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .form-section h3 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .form-section h3::before {
            content: "📋";
            margin-right: 10px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #e53e3e;
        }

        .alert ul {
            margin-left: 20px;
            margin-top: 10px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .helper-text {
            font-size: 13px;
            color: #718096;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>➕ <?= $page_title ?></h1>
            <p>Remplissez les informations pour créer une nouvelle salle</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>⚠️ Erreur(s) détectée(s):</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="">
                <!-- Informations de base -->
                <div class="form-section">
                    <h3>Informations de base</h3>
                    
                    <div class="form-group">
                        <label>
                            Nom de la salle <span class="required">*</span>
                        </label>
                        <input type="text" name="nom" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" 
                               placeholder="Ex: Salle A1, Amphithéâtre 1" required>
                        <div class="helper-text">Nom unique identifiant la salle</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                Bâtiment <span class="required">*</span>
                            </label>
                            <input type="text" name="batiment" value="<?= htmlspecialchars($_POST['batiment'] ?? '') ?>" 
                                   placeholder="Ex: Bâtiment A" required>
                        </div>

                        <div class="form-group">
                            <label>Étage</label>
                            <input type="text" name="etage" value="<?= htmlspecialchars($_POST['etage'] ?? '') ?>" 
                                   placeholder="Ex: 1er, RDC">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            Capacité <span class="required">*</span>
                        </label>
                        <input type="number" name="capacite" value="<?= htmlspecialchars($_POST['capacite'] ?? '20') ?>" 
                               min="1" required>
                        <div class="helper-text">Nombre maximum de personnes pouvant être accueillies</div>
                    </div>
                </div>

                <!-- Équipements -->
                <div class="form-section">
                    <h3>Équipements disponibles</h3>
                    
                    <div class="equipements-grid">
                        <?php 
                        $equipements_disponibles = [
                            'projecteur' => 'Vidéoprojecteur',
                            'tableau' => 'Tableau blanc',
                            'ordinateur' => 'Ordinateur',
                            'visio' => 'Système de visioconférence',
                            'climatisation' => 'Climatisation',
                            'wifi' => 'Wi-Fi',
                            'prises' => 'Prises électriques',
                            'son' => 'Système audio'
                        ];
                        
                        $equipements_selectionnes = $_POST['equipements'] ?? [];
                        
                        foreach ($equipements_disponibles as $key => $label): 
                        ?>
                            <div class="checkbox-group">
                                <input type="checkbox" name="equipements[]" value="<?= $key ?>" 
                                       id="eq_<?= $key ?>" 
                                       <?= in_array($key, $equipements_selectionnes) ? 'checked' : '' ?>>
                                <label for="eq_<?= $key ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Disponibilité -->
                <div class="form-section">
                    <h3>Disponibilité</h3>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="disponible" id="disponible" 
                               <?= (!isset($_POST['disponible']) || isset($_POST['disponible'])) ? 'checked' : '' ?>>
                        <label for="disponible">Cette salle est disponible pour les soutenances</label>
                    </div>
                    <div class="helper-text">Décochez cette case si la salle est temporairement indisponible</div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <a href="liste.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-primary">✓ Ajouter la salle</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>


