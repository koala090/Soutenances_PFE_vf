
USE gestion_soutenances;

-- NETTOYAGE DES DONNÉES EXISTANTES
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE messages;
TRUNCATE TABLE jurys;
TRUNCATE TABLE soutenances;
TRUNCATE TABLE disponibilites;
TRUNCATE TABLE periodes_disponibilite;
TRUNCATE TABLE rapports;
TRUNCATE TABLE projets;
TRUNCATE TABLE utilisateurs;
TRUNCATE TABLE salles;
TRUNCATE TABLE filieres;

SET FOREIGN_KEY_CHECKS = 1;

-- INSERTION : FILIÈRES EIDIA
INSERT INTO filieres (code, nom, description, duree_soutenance) VALUES
('CS', 'Cybersecurity', 'Formation en sécurité informatique, cryptographie et protection des systèmes', 60),
('AI', 'Artificial Intelligence', 'Formation en intelligence artificielle, machine learning et deep learning', 60),
('BD', 'Big Data', 'Formation en analyse de données massives, data science et business intelligence', 60),
('ROB', 'Robotics', 'Formation en robotique, systèmes embarqués et automatisation', 60),
('FS', 'Full Stack Development', 'Formation en développement web full stack et applications mobiles', 60);

-- INSERTION : COORDINATEURS
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, filiere_id, specialites, telephone, bureau, actif) VALUES
-- Cybersecurity
('Ait Tachakoucht', 'Taha', 't.aittachakoucht@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinateur', 1, '["Cybersecurity", "Network Security", "Ethical Hacking"]', '0612000001', 'Bureau CS-01', TRUE),

-- Artificial Intelligence
('Abbadi', 'Asmae', 'a.abbadi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinateur', 2, '["Artificial Intelligence", "Machine Learning", "Computer Vision"]', '0612000002', 'Bureau AI-01', TRUE),

-- Robotics
('Elkari', 'Bader', 'b.elkari@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinateur', 4, '["Robotics", "Embedded Systems", "Automation"]', '0612000003', 'Bureau ROB-01', TRUE),

-- Big Data
('Ourabeh', 'Loubna', 'l.ourabeh@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinateur', 3, '["Big Data", "Data Science", "Analytics"]', '0612000004', 'Bureau BD-01', TRUE),

-- Full Stack
('Elmouhtadi', 'Meryem', 'm.elmouhtadi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinateur', 5, '["Full Stack", "Web Development", "Mobile Apps"]', '0612000005', 'Bureau FS-01', TRUE);

-- Mise à jour des coordinateurs de filières
UPDATE filieres SET coordinateur_id = 1 WHERE code = 'CS';
UPDATE filieres SET coordinateur_id = 2 WHERE code = 'AI';
UPDATE filieres SET coordinateur_id = 3 WHERE code = 'BD';
UPDATE filieres SET coordinateur_id = 4 WHERE code = 'ROB';
UPDATE filieres SET coordinateur_id = 5 WHERE code = 'FS';

-- =============================================
-- INSERTION : PROFESSEURS
-- =============================================

INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, filiere_id, specialites, telephone, bureau, max_encadrements, actif) VALUES

-- Professeur multidisciplinaire (Web, Cloud, Network, DevSecOps)
('Amamou', 'Ahmed', 'a.amamou@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 5, 
 '["Web Development", "Cloud Computing", "Network", "Application Répartie", "DevSecOps", "Microservices"]', 
 '0612100001', 'Bureau Prof-01', 6, TRUE),

-- Professeur AI/ML/DL
('Workneh', 'Abebaw Degu', 'a.workneh@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 2, 
 '["Artificial Intelligence", "Machine Learning", "Deep Learning", "Neural Networks", "Computer Vision", "NLP"]', 
 '0612100002', 'Bureau Prof-02', 6, TRUE),

-- Professeurs Cybersecurity
('Benali', 'Karim', 'k.benali@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 1, 
 '["Cybersecurity", "Cryptographie", "Penetration Testing", "Security Audit"]', 
 '0612100003', 'Bureau Prof-03', 5, TRUE),

('Fahmi', 'Sara', 's.fahmi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 1, 
 '["Network Security", "Firewall", "Intrusion Detection", "SIEM"]', 
 '0612100004', 'Bureau Prof-04', 5, TRUE),

-- Professeurs Big Data
('Tazi', 'Youssef', 'y.tazi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 3, 
 '["Big Data", "Hadoop", "Spark", "Data Mining", "Business Intelligence"]', 
 '0612100005', 'Bureau Prof-05', 5, TRUE),

('Alami', 'Fatima', 'f.alami@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 3, 
 '["Data Science", "Python", "R", "Statistical Analysis", "Visualization"]', 
 '0612100006', 'Bureau Prof-06', 5, TRUE),

-- Professeurs Robotics
('Idrissi', 'Hassan', 'h.idrissi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 4, 
 '["Robotics", "Arduino", "Raspberry Pi", "IoT", "Sensors"]', 
 '0612100007', 'Bureau Prof-07', 4, TRUE),

('Benjelloun', 'Nadia', 'n.benjelloun@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 4, 
 '["Embedded Systems", "Real-Time OS", "Microcontrollers", "Automation"]', 
 '0612100008', 'Bureau Prof-08', 4, TRUE),

-- Professeurs Full Stack
('Chraibi', 'Omar', 'o.chraibi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 5, 
 '["React", "Node.js", "MongoDB", "Express", "JavaScript", "TypeScript"]', 
 '0612100009', 'Bureau Prof-09', 5, TRUE),

('Filali', 'Samira', 's.filali@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'professeur', 5, 
 '["PHP", "Laravel", "Symfony", "MySQL", "API REST", "GraphQL"]', 
 '0612100010', 'Bureau Prof-10', 5, TRUE);

-- =============================================
-- INSERTION : ÉTUDIANTS PROMOTION 2024-2025
-- =============================================

INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, filiere_id, telephone, actif) VALUES

-- Cybersecurity
('Lyzoul', 'Siham', 'siham.lyzoul@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 1, '0612200001', TRUE),
('Hadile', 'Hadile', 'hadile.hadile@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 1, '0612200002', TRUE),
('Boulanouar', 'Zahira', 'zahira.boulanouar@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 1, '0612200003', TRUE),

-- Artificial Intelligence
('Dallal', 'Oumayma', 'oumayma.dallal@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 2, '0612200004', TRUE),
('Fathi', 'Abderahman', 'abderahman.fathi@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 2, '0612200005', TRUE),
('Almetalsi', 'Ikram', 'ikram.almetalsi@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 2, '0612200006', TRUE),

-- Big Data
('Aitoussaire', 'Meryem', 'meryem.aitoussaire@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 3, '0612200007', TRUE),
('Douae', 'Douae', 'douae.douae@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 3, '0612200008', TRUE),

-- Robotics
('Belghali', 'Hajar', 'hajar.belghali@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 4, '0612200009', TRUE),

-- Full Stack
('Zzzoutine', 'Aya', 'aya.zzzoutine@eidia.ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'etudiant', 5, '0612200010', TRUE);

-- =============================================
-- INSERTION : ADMINISTRATION
-- =============================================

-- Directeur
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, telephone, bureau, actif) VALUES
('Bennani', 'Rachid', 'r.bennani@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'directeur', '0612300001', 'Direction', TRUE);

-- Assistantes administratives
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, filiere_id, telephone, bureau, actif) VALUES
('Alaoui', 'Samira', 's.alaoui@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'assistante', 1, '0612300002', 'Secrétariat', TRUE),
('Fassi', 'Khadija', 'k.fassi@ueuromed.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'assistante', 2, '0612300003', 'Secrétariat', TRUE);

-- =============================================
-- INSERTION : SALLES UEUROMED
-- Nomenclature : Amphithéâtres (amphi1, amphi2, amphi3)
--                Salles (batiment.etage.salle)
--                Bâtiments : 1 à 4
--                Étages : 0 à 4
-- =============================================

INSERT INTO salles (nom, batiment, etage, capacite, equipements, disponible) VALUES

-- AMPHITHÉÂTRES
('Amphi 1', 'Bâtiment Principal', '0', 120, 
 '["Vidéoprojecteur 4K", "Système audio", "Microphones", "Tableau interactif", "Climatisation", "WiFi"]', TRUE),

('Amphi 2', 'Bâtiment Principal', '0', 100, 
 '["Vidéoprojecteur HD", "Système audio", "Microphones", "Tableau blanc", "Climatisation", "WiFi"]', TRUE),

('Amphi 3', 'Bâtiment Principal', '0', 80, 
 '["Vidéoprojecteur", "Système audio", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 1 - Étage 0
('Salle 1.0.1', 'Bâtiment 1', '0', 30, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi", "Prises électriques"]', TRUE),

('Salle 1.0.2', 'Bâtiment 1', '0', 30, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 1 - Étage 1
('Salle 1.1.1', 'Bâtiment 1', '1', 25, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

('Salle 1.1.2', 'Bâtiment 1', '1', 25, 
 '["Vidéoprojecteur", "Tableau interactif", "WiFi"]', TRUE),

-- BÂTIMENT 1 - Étage 2
('Salle 1.2.1', 'Bâtiment 1', '2', 35, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi", "Système audio"]', TRUE),

('Salle 1.2.2', 'Bâtiment 1', '2', 30, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 2 - Étage 0
('Salle 2.0.1', 'Bâtiment 2', '0', 40, 
 '["Ordinateurs", "Vidéoprojecteur", "WiFi", "Logiciels dev"]', TRUE),

('Salle 2.0.2', 'Bâtiment 2', '0', 35, 
 '["Ordinateurs", "Vidéoprojecteur", "WiFi"]', TRUE),

-- BÂTIMENT 2 - Étage 1
('Salle 2.1.1', 'Bâtiment 2', '1', 30, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi", "Prises réseau"]', TRUE),

('Salle 2.1.2', 'Bâtiment 2', '1', 28, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 2 - Étage 2
('Salle 2.2.1', 'Bâtiment 2', '2', 30, 
 '["GPU Workstation", "Vidéoprojecteur", "Tableau interactif", "WiFi"]', TRUE),

('Salle 2.2.2', 'Bâtiment 2', '2', 25, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 3 - Étage 1
('Salle 3.1.1', 'Bâtiment 3', '1', 30, 
 '["Équipements robotique", "Imprimante 3D", "Oscilloscopes", "WiFi"]', TRUE),

('Salle 3.1.2', 'Bâtiment 3', '1', 25, 
 '["Arduino", "Raspberry Pi", "Multimètres", "Vidéoprojecteur", "WiFi"]', TRUE),

-- BÂTIMENT 3 - Étage 2
('Salle 3.2.1', 'Bâtiment 3', '2', 35, 
 '["Serveur de calcul", "Écrans multiples", "Vidéoprojecteur", "WiFi"]', TRUE),

('Salle 3.2.2', 'Bâtiment 3', '2', 30, 
 '["Vidéoprojecteur", "Tableau blanc", "WiFi"]', TRUE),

-- BÂTIMENT 4 - Étage 0
('Salle 4.0.1', 'Bâtiment 4', '0', 20, 
 '["Système visio HD", "Écrans multiples", "Microphones", "Caméra PTZ", "WiFi"]', TRUE),

('Salle 4.0.2', 'Bâtiment 4', '0', 15, 
 '["Vidéoprojecteur", "Tableau blanc", "Visioconférence", "WiFi"]', TRUE);

-- =============================================
-- INSERTION : PROJETS EXEMPLES
-- =============================================

INSERT INTO projets (titre, description, mots_cles, etudiant_id, binome_id, encadrant_id, filiere_id, annee_universitaire, statut, date_affectation) VALUES

-- Cybersecurity
('Système de détection d''intrusion par IA', 
 'Développement d''un IDS intelligent utilisant le machine learning pour détecter les attaques réseau en temps réel avec analyse comportementale et génération d''alertes automatiques', 
 '["Cybersecurity", "AI", "IDS", "Python", "Scapy", "Machine Learning"]', 
 16, NULL, 8, 1, '2024-2025', 'en_cours', '2024-10-15 10:00:00'),

-- AI
('Chatbot multilingue avec NLP', 
 'Création d''un assistant conversationnel intelligent supportant l''arabe, le français et l''anglais avec compréhension contextuelle et apprentissage continu', 
 '["AI", "NLP", "Deep Learning", "TensorFlow", "Python", "Multilingual"]', 
 19, 20, 7, 2, '2024-2025', 'en_cours', '2024-10-16 14:00:00'),

-- Big Data
('Plateforme d''analyse de données massives', 
 'Système distribué pour le traitement et l''analyse de données Big Data avec visualisation interactive et tableaux de bord analytiques', 
 '["Big Data", "Hadoop", "Spark", "Python", "Visualization", "ETL"]', 
 22, 23, 10, 3, '2024-2025', 'rapport_soumis', '2024-10-17 09:00:00'),

-- Robotics
('Robot autonome de navigation', 
 'Conception d''un robot mobile autonome capable de naviguer dans un environnement inconnu avec évitement d''obstacles et cartographie SLAM', 
 '["Robotics", "Arduino", "Sensors", "Computer Vision", "SLAM", "ROS"]', 
 24, NULL, 12, 4, '2024-2025', 'valide_encadrant', '2024-10-18 11:00:00'),

-- Full Stack
('Application de gestion des soutenances PFE', 
 'Plateforme web complète pour automatiser la gestion des soutenances : inscription, affectation intelligente, planification automatique et génération de documents', 
 '["Full Stack", "PHP", "MySQL", "React", "JavaScript", "FPDF"]', 
 25, NULL, 6, 5, '2024-2025', 'en_cours', '2024-10-19 15:00:00');

-- =============================================
-- STATISTIQUES D'INSERTION
-- =============================================

SELECT '========================================' AS '';
SELECT '✓ DONNÉES UEUROMED INSÉRÉES' AS 'STATUS';
SELECT '========================================' AS '';

SELECT '' AS '';
SELECT 'ÉCOLE : EIDIA - UEUROMED' AS '═══════════════════════════════════════';

SELECT '' AS '';
SELECT 'FILIÈRES' AS '───────────────────────────────────────';
SELECT f.code AS 'Code', f.nom AS 'Filière', CONCAT(u.prenom, ' ', u.nom) AS 'Coordinateur'
FROM filieres f
LEFT JOIN utilisateurs u ON f.coordinateur_id = u.id
ORDER BY f.id;

SELECT '' AS '';
SELECT 'UTILISATEURS PAR RÔLE' AS '───────────────────────────────────────';
SELECT role AS 'Rôle', COUNT(*) AS 'Nombre'
FROM utilisateurs
GROUP BY role
ORDER BY FIELD(role, 'directeur', 'coordinateur', 'professeur', 'etudiant', 'assistante');

SELECT '' AS '';
SELECT 'ÉTUDIANTS PAR FILIÈRE' AS '───────────────────────────────────────';
SELECT f.code AS 'Filière', f.nom AS 'Nom', COUNT(u.id) AS 'Étudiants'
FROM filieres f
LEFT JOIN utilisateurs u ON f.id = u.filiere_id AND u.role = 'etudiant'
GROUP BY f.id, f.code, f.nom
ORDER BY f.code;

SELECT '' AS '';
SELECT 'PROJETS PAR STATUT' AS '───────────────────────────────────────';
SELECT statut AS 'Statut', COUNT(*) AS 'Nombre'
FROM projets
GROUP BY statut
ORDER BY COUNT(*) DESC;

SELECT '' AS '';
SELECT 'COMPTES PRINCIPAUX' AS '───────────────────────────────────────';
SELECT 
    role AS 'Rôle',
    CONCAT(prenom, ' ', nom) AS 'Nom',
    email AS 'Email'
FROM utilisateurs
WHERE role IN ('directeur', 'coordinateur')
ORDER BY FIELD(role, 'directeur', 'coordinateur'), nom;

SELECT '' AS '';
SELECT 'INFORMATIONS IMPORTANTES' AS '═══════════════════════════════════════';
SELECT 'Mot de passe pour TOUS les comptes : password123' AS 'Info';
SELECT 'Format email étudiants : prenom.nom@eidia.ueuromed.org' AS 'Info';
SELECT 'Format email staff : p.nom@ueuromed.org' AS 'Info';
SELECT 'Nomenclature salles : Amphi1-3 et Batiment.Etage.Salle (ex: 1.2.1)' AS 'Info';

SELECT '' AS '';
SELECT 'SALLES DISPONIBLES' AS '───────────────────────────────────────';
SELECT COUNT(*) AS 'Total Salles' FROM salles;
SELECT COUNT(*) AS 'Amphithéâtres' FROM salles WHERE nom LIKE 'Amphi%';
SELECT COUNT(*) AS 'Salles de cours' FROM salles WHERE nom NOT LIKE 'Amphi%';

SELECT '' AS '';
SELECT '========================================' AS '';
SELECT '✓ BASE PRÊTE POUR UTILISATION' AS '';
SELECT '========================================' AS '';