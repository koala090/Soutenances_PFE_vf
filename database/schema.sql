-- =============================================
-- SCHÉMA DE LA BASE DE DONNÉES
-- Application : Gestion des Soutenances PFE
-- =============================================
-- Projet : Système de Gestion des Soutenances
-- Module : Base de données - Structure uniquement
-- Auteur : Étudiant 1 (Authentification & Utilisateurs)
-- Date : 2026-01-03
-- Version : 1.0
-- =============================================
-- Description :
-- Ce fichier contient uniquement la structure de la base
-- de données (tables, contraintes, index) sans données.
-- Utiliser data.sql pour insérer les données de test.
-- =============================================

-- Suppression de la base si elle existe
DROP DATABASE IF EXISTS gestion_soutenances;

-- Création de la base de données
CREATE DATABASE gestion_soutenances 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_general_ci;

-- Utilisation de la base
USE gestion_soutenances;

-- =============================================
-- TABLE : filieres
-- Description : Gestion des filières de formation
-- =============================================
CREATE TABLE filieres (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    code                VARCHAR(20) UNIQUE NOT NULL COMMENT 'Code unique de la filière (ex: GI, GE)',
    nom                 VARCHAR(150) NOT NULL COMMENT 'Nom complet de la filière',
    description         TEXT COMMENT 'Description détaillée de la filière',
    coordinateur_id     INT COMMENT 'Référence vers le coordinateur de la filière',
    duree_soutenance    INT DEFAULT 60 COMMENT 'Durée standard d''une soutenance en minutes',
    date_creation       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Filières de formation';

-- =============================================
-- TABLE : utilisateurs
-- Description : Tous les utilisateurs du système
-- =============================================
CREATE TABLE utilisateurs (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    nom                 VARCHAR(100) NOT NULL,
    prenom              VARCHAR(100) NOT NULL,
    email               VARCHAR(255) UNIQUE NOT NULL COMMENT 'Email unique pour connexion',
    mot_de_passe        VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt du mot de passe',
    role                ENUM('etudiant', 'professeur', 'coordinateur', 'directeur', 'assistante') NOT NULL,
    filiere_id          INT COMMENT 'Filière d''appartenance',
    specialites         JSON COMMENT 'Spécialités du professeur (ex: ["IA", "Web"])',
    max_encadrements    INT DEFAULT 5 COMMENT 'Nombre maximum de projets encadrables',
    telephone           VARCHAR(20),
    bureau              VARCHAR(50) COMMENT 'Numéro de bureau',
    actif               BOOLEAN DEFAULT TRUE COMMENT 'Compte actif ou désactivé',
    date_creation       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Utilisateurs du système (tous rôles)';

-- =============================================
-- TABLE : projets
-- Description : Projets de fin d'études
-- =============================================
CREATE TABLE projets (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    titre               VARCHAR(255) NOT NULL,
    description         TEXT,
    mots_cles           JSON COMMENT 'Mots-clés du projet pour matching',
    etudiant_id         INT NOT NULL COMMENT 'Étudiant principal',
    binome_id           INT COMMENT 'Binôme (optionnel)',
    encadrant_id        INT COMMENT 'Professeur encadrant',
    filiere_id          INT NOT NULL,
    annee_universitaire VARCHAR(9) NOT NULL COMMENT 'Format: 2024-2025',
    statut              ENUM('inscrit', 'encadrant_affecte', 'en_cours', 'rapport_soumis', 
                             'valide_encadrant', 'planifie', 'soutenu', 'ajourne') 
                        DEFAULT 'inscrit',
    date_inscription    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_affectation    DATETIME COMMENT 'Date d''affectation de l''encadrant',
    
    FOREIGN KEY (etudiant_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    FOREIGN KEY (binome_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    FOREIGN KEY (encadrant_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Projets de fin d''études';

-- =============================================
-- TABLE : rapports
-- Description : Rapports uploadés par les étudiants
-- =============================================
CREATE TABLE rapports (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    projet_id           INT NOT NULL,
    version             INT DEFAULT 1 COMMENT 'Numéro de version du rapport',
    nom_fichier         VARCHAR(255) NOT NULL,
    chemin              VARCHAR(500) NOT NULL COMMENT 'Chemin de stockage du fichier',
    taille              BIGINT NOT NULL COMMENT 'Taille en octets',
    resume              TEXT COMMENT 'Résumé du rapport',
    valide_encadrant    BOOLEAN DEFAULT FALSE,
    date_validation     DATETIME,
    commentaire_encadrant TEXT,
    date_upload         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rapports de projets';

-- =============================================
-- TABLE : salles
-- Description : Salles disponibles pour les soutenances
-- =============================================
CREATE TABLE salles (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    nom                 VARCHAR(50) NOT NULL,
    batiment            VARCHAR(50),
    etage               VARCHAR(10),
    capacite            INT DEFAULT 20 COMMENT 'Nombre de places',
    equipements         JSON COMMENT 'Liste des équipements disponibles',
    disponible          BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Salles de soutenance';

-- =============================================
-- TABLE : periodes_disponibilite
-- Description : Périodes de saisie des disponibilités
-- =============================================
CREATE TABLE periodes_disponibilite (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    filiere_id          INT NOT NULL,
    annee_universitaire VARCHAR(9) NOT NULL,
    date_debut_saisie   DATE NOT NULL COMMENT 'Début période de saisie',
    date_fin_saisie     DATE NOT NULL COMMENT 'Fin période de saisie',
    date_debut_soutenances DATE NOT NULL COMMENT 'Début des soutenances',
    date_fin_soutenances DATE NOT NULL COMMENT 'Fin des soutenances',
    statut              ENUM('a_venir', 'en_cours', 'cloturee') DEFAULT 'a_venir',
    
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Périodes de disponibilité';

-- =============================================
-- TABLE : disponibilites
-- Description : Disponibilités des professeurs
-- =============================================
CREATE TABLE disponibilites (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    professeur_id       INT NOT NULL,
    periode_id          INT NOT NULL,
    date_disponible     DATE NOT NULL,
    heure_debut         TIME NOT NULL,
    heure_fin           TIME NOT NULL,
    date_saisie         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (professeur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    FOREIGN KEY (periode_id) REFERENCES periodes_disponibilite(id) ON DELETE CASCADE,
    UNIQUE KEY unique_dispo (professeur_id, periode_id, date_disponible, heure_debut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Disponibilités des professeurs';

-- =============================================
-- TABLE : soutenances
-- Description : Planning des soutenances
-- =============================================
CREATE TABLE soutenances (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    projet_id           INT NOT NULL UNIQUE,
    salle_id            INT NOT NULL,
    date_soutenance     DATE NOT NULL,
    heure_debut         TIME NOT NULL,
    heure_fin           TIME NOT NULL,
    statut              ENUM('planifiee', 'confirmee', 'en_cours', 'terminee', 'reportee', 'annulee') 
                        DEFAULT 'planifiee',
    note_finale         DECIMAL(4,2) COMMENT 'Note sur 20',
    mention             ENUM('passable', 'assez_bien', 'bien', 'tres_bien', 'excellent'),
    observations        TEXT COMMENT 'Observations du jury',
    pv_genere           BOOLEAN DEFAULT FALSE,
    pv_signe            BOOLEAN DEFAULT FALSE,
    chemin_pv           VARCHAR(500) COMMENT 'Chemin du PV signé',
    date_creation       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (salle_id) REFERENCES salles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Planning des soutenances';

-- =============================================
-- TABLE : jurys
-- Description : Composition des jurys de soutenance
-- =============================================
CREATE TABLE jurys (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    soutenance_id       INT NOT NULL,
    professeur_id       INT NOT NULL,
    role_jury           ENUM('president', 'encadrant', 'examinateur', 'rapporteur', 'invite') NOT NULL,
    convocation_envoyee BOOLEAN DEFAULT FALSE,
    date_convocation    DATETIME,
    presence_confirmee  BOOLEAN,
    note_attribuee      DECIMAL(4,2) COMMENT 'Note individuelle du membre',
    appreciation        TEXT,
    date_notation       DATETIME,
    
    FOREIGN KEY (soutenance_id) REFERENCES soutenances(id) ON DELETE CASCADE,
    FOREIGN KEY (professeur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_jury_membre (soutenance_id, professeur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Membres des jurys';

-- =============================================
-- TABLE : messages
-- Description : Messagerie interne projet-encadrant
-- =============================================
CREATE TABLE messages (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    projet_id           INT NOT NULL,
    expediteur_id       INT NOT NULL,
    contenu             TEXT NOT NULL,
    lu                  BOOLEAN DEFAULT FALSE,
    date_envoi          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (expediteur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Messagerie interne';

-- =============================================
-- INDEX POUR OPTIMISATION DES PERFORMANCES
-- =============================================

-- Index sur utilisateurs
CREATE INDEX idx_utilisateurs_role ON utilisateurs(role);
CREATE INDEX idx_utilisateurs_filiere ON utilisateurs(filiere_id);
CREATE INDEX idx_utilisateurs_actif ON utilisateurs(actif);

-- Index sur projets
CREATE INDEX idx_projets_statut ON projets(statut);
CREATE INDEX idx_projets_encadrant ON projets(encadrant_id);
CREATE INDEX idx_projets_filiere ON projets(filiere_id);
CREATE INDEX idx_projets_etudiant ON projets(etudiant_id);
CREATE INDEX idx_projets_annee ON projets(annee_universitaire);

-- Index sur soutenances
CREATE INDEX idx_soutenances_date ON soutenances(date_soutenance);
CREATE INDEX idx_soutenances_statut ON soutenances(statut);
CREATE INDEX idx_soutenances_salle ON soutenances(salle_id);

-- Index sur disponibilités
CREATE INDEX idx_disponibilites_date ON disponibilites(date_disponible);
CREATE INDEX idx_disponibilites_professeur ON disponibilites(professeur_id);
CREATE INDEX idx_disponibilites_periode ON disponibilites(periode_id);

-- Index sur jurys
CREATE INDEX idx_jurys_professeur ON jurys(professeur_id);
CREATE INDEX idx_jurys_soutenance ON jurys(soutenance_id);
CREATE INDEX idx_jurys_role ON jurys(role_jury);

-- Index sur messages
CREATE INDEX idx_messages_projet ON messages(projet_id);
CREATE INDEX idx_messages_expediteur ON messages(expediteur_id);
CREATE INDEX idx_messages_lu ON messages(lu);

-- Index sur rapports
CREATE INDEX idx_rapports_projet ON rapports(projet_id);
CREATE INDEX idx_rapports_valide ON rapports(valide_encadrant);

-- =============================================
-- VUES UTILES
-- =============================================

-- Vue : Projets avec informations complètes
CREATE VIEW vue_projets_complets AS
SELECT 
    p.id,
    p.titre,
    p.statut,
    p.annee_universitaire,
    CONCAT(e.prenom, ' ', e.nom) AS etudiant,
    e.email AS email_etudiant,
    CONCAT(b.prenom, ' ', b.nom) AS binome,
    CONCAT(enc.prenom, ' ', enc.nom) AS encadrant,
    enc.email AS email_encadrant,
    f.nom AS filiere,
    p.date_inscription,
    p.date_affectation
FROM projets p
LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
LEFT JOIN utilisateurs b ON p.binome_id = b.id
LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
LEFT JOIN filieres f ON p.filiere_id = f.id;

-- Vue : Statistiques par filière
CREATE VIEW vue_stats_filieres AS
SELECT 
    f.nom AS filiere,
    COUNT(DISTINCT p.id) AS nb_projets,
    COUNT(DISTINCT CASE WHEN p.encadrant_id IS NOT NULL THEN p.id END) AS nb_projets_avec_encadrant,
    COUNT(DISTINCT CASE WHEN p.statut = 'soutenu' THEN p.id END) AS nb_soutenances_terminees,
    COUNT(DISTINCT u.id) AS nb_etudiants,
    COUNT(DISTINCT prof.id) AS nb_professeurs
FROM filieres f
LEFT JOIN projets p ON f.id = p.filiere_id AND p.annee_universitaire = '2024-2025'
LEFT JOIN utilisateurs u ON f.id = u.filiere_id AND u.role = 'etudiant'
LEFT JOIN utilisateurs prof ON f.id = prof.filiere_id AND prof.role = 'professeur'
GROUP BY f.id, f.nom;

-- =============================================
-- TRIGGERS
-- =============================================

-- Trigger : Mise à jour automatique de la date d'affectation
DELIMITER //
CREATE TRIGGER tr_projet_affectation 
BEFORE UPDATE ON projets
FOR EACH ROW
BEGIN
    -- Si un encadrant est affecté pour la première fois
    IF NEW.encadrant_id IS NOT NULL AND OLD.encadrant_id IS NULL THEN
        SET NEW.date_affectation = NOW();
        -- Mise à jour du statut si encore à 'inscrit'
        IF OLD.statut = 'inscrit' THEN
            SET NEW.statut = 'encadrant_affecte';
        END IF;
    END IF;
END//
DELIMITER ;

-- Trigger : Validation automatique du statut lors de la validation du rapport
DELIMITER //
CREATE TRIGGER tr_rapport_validation
AFTER UPDATE ON rapports
FOR EACH ROW
BEGIN
    -- Si le rapport est validé par l'encadrant
    IF NEW.valide_encadrant = TRUE AND OLD.valide_encadrant = FALSE THEN
        UPDATE projets 
        SET statut = 'valide_encadrant' 
        WHERE id = NEW.projet_id AND statut = 'rapport_soumis';
    END IF;
END//
DELIMITER ;

-- =============================================
-- PROCÉDURES STOCKÉES
-- =============================================

-- Procédure : Obtenir les statistiques d'un professeur
DELIMITER //
CREATE PROCEDURE sp_stats_professeur(IN prof_id INT)
BEGIN
    SELECT 
        COUNT(DISTINCT p.id) AS nb_projets_encadres,
        COUNT(DISTINCT j.soutenance_id) AS nb_participations_jurys,
        COUNT(DISTINCT CASE WHEN j.role_jury = 'president' THEN j.id END) AS nb_presidences,
        AVG(s.note_finale) AS moyenne_notes
    FROM utilisateurs u
    LEFT JOIN projets p ON u.id = p.encadrant_id
    LEFT JOIN jurys j ON u.id = j.professeur_id
    LEFT JOIN soutenances s ON j.soutenance_id = s.id
    WHERE u.id = prof_id;
END//
DELIMITER ;

-- =============================================
-- FONCTION : Calculer la charge d'un professeur
-- =============================================
DELIMITER //
CREATE FUNCTION fn_charge_professeur(prof_id INT, annee VARCHAR(9))
RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE charge INT;
    
    SELECT COUNT(*) INTO charge
    FROM projets
    WHERE encadrant_id = prof_id 
    AND annee_universitaire = annee;
    
    RETURN charge;
END//
DELIMITER ;

-- =============================================
-- AFFICHAGE DES INFORMATIONS
-- =============================================

SELECT '========================================' AS '';
SELECT '✓ SCHÉMA CRÉÉ AVEC SUCCÈS' AS 'STATUS';
SELECT '========================================' AS '';

SELECT '' AS '';
SELECT 'STRUCTURE DE LA BASE' AS '═══════════════════════════════════════';

SELECT COUNT(*) AS 'Tables créées' 
FROM information_schema.tables 
WHERE table_schema = 'gestion_soutenances' AND table_type = 'BASE TABLE';

SELECT COUNT(*) AS 'Vues créées'
FROM information_schema.views
WHERE table_schema = 'gestion_soutenances';

SELECT COUNT(DISTINCT table_name) AS 'Tables avec index'
FROM information_schema.statistics 
WHERE table_schema = 'gestion_soutenances';

SELECT COUNT(*) AS 'Triggers créés'
FROM information_schema.triggers
WHERE trigger_schema = 'gestion_soutenances';

SELECT COUNT(*) AS 'Procédures stockées'
FROM information_schema.routines
WHERE routine_schema = 'gestion_soutenances' AND routine_type = 'PROCEDURE';

SELECT COUNT(*) AS 'Fonctions créées'
FROM information_schema.routines
WHERE routine_schema = 'gestion_soutenances' AND routine_type = 'FUNCTION';

SELECT '' AS '';
SELECT '========================================' AS '';
SELECT 'Schéma prêt - Utilisez data.sql pour' AS '';
SELECT 'insérer les données de test' AS '';
SELECT '========================================' AS '';