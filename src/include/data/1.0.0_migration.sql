ALTER TABLE membres_operations RENAME TO membres_operations_old;
ALTER TABLE membres_categories RENAME TO membres_categories_old;

DROP TABLE fichiers_compta_journal; -- Inutilisé à ce jour

-- Fix: comptes de clôture et fermeture
UPDATE compta_comptes SET libelle = 'Bilan d''ouverture' WHERE id = '890' AND libelle = 'Bilan de clôture';
INSERT OR REPLACE INTO compta_comptes (id, parent, libelle, position) VALUES ('891', '89', 'Bilan de clôture', 0);

-- N'est pas utilisé
DELETE FROM config WHERE cle = 'categorie_dons' OR cle = 'categorie_cotisations';

.read 1.0.0_schema.sql

-------- MIGRATION COMPTA ---------
INSERT INTO acc_charts (id, country, code, label) VALUES (1, 'FR', 'PCGA1999', 'Plan comptable associatif 1999');

-- Migration comptes de code comme identifiant à ID unique
-- Inversement valeurs actif/passif et produit/charge
INSERT INTO acc_accounts (id, id_chart, code, label, position, user)
	SELECT NULL, 1, id, libelle,
	CASE
		WHEN position = 1 THEN 2
		WHEN position = 2 THEN 1
		WHEN position = 3 THEN 3
		WHEN position = 4 THEN 5
		WHEN position = 8 THEN 4
		-- Suppression de la position "charge ou produit" qui n'a aucun sens
		WHEN position = 12 AND id LIKE '6%' THEN 4
		WHEN position = 12 AND id LIKE '7%' THEN 5
		WHEN position = 12 THEN 0
		ELSE 0
	END,
	CASE WHEN plan_comptable = 1 THEN 0 ELSE 1 END
	FROM compta_comptes;

-- Migrations projets vers comptes analytiques
INSERT INTO acc_accounts (id_chart, code, label, position, user, type)
	VALUES (1, '99', 'Projets', 0, 1, 0);

INSERT INTO acc_accounts (id_chart, code, label, position, user, type)
	SELECT 1, '99' || substr('0000' || id, -4), libelle, 0, 1, 7 FROM compta_projets;

-- Mise à jour de la position pour les comptes de tiers qui peuvent varier actif ou passif
UPDATE acc_accounts SET position = 3 WHERE code IN (4010, 4110, 4210, 428, 438);

-- Mise à jour position comptes bancaires, qui peuvent être à découvert et donc changer de côté au bilan
UPDATE acc_accounts SET position = 3 WHERE code LIKE '512%';

-- Migration comptes bancaires
UPDATE acc_accounts SET type = 1 WHERE code IN (SELECT id FROM compta_comptes_bancaires);

-- Caisse
UPDATE acc_accounts SET type = 2 WHERE code = '530';

-- Chèques et carte à encaisser
UPDATE acc_accounts SET type = 3 WHERE code = '5112' OR code = '5113';

-- Comptes d'ouverture et de clôture
UPDATE acc_accounts SET type = 9, position = 0 WHERE code = '890';
UPDATE acc_accounts SET type = 10, position = 0 WHERE code = '891';

-- Comptes de tiers
UPDATE acc_accounts SET type = 4 WHERE code IN (SELECT id FROM compta_comptes WHERE id LIKE '4%' AND plan_comptable = 0 AND desactive = 0);

-- Recopie des mouvements
INSERT INTO acc_transactions (id, label, notes, reference, date, id_year, id_creator)
	SELECT id, libelle, remarques, numero_piece, date, id_exercice, id_auteur
	FROM compta_journal;

-- Recettes
UPDATE acc_transactions SET type = 1 WHERE id IN (SELECT id FROM compta_journal WHERE id_categorie IN (SELECT id FROM compta_categories WHERE type = 1));

-- Dépenses
UPDATE acc_transactions SET type = 2 WHERE id IN (SELECT id FROM compta_journal WHERE id_categorie IN (SELECT id FROM compta_categories WHERE type = -1));

-- Virements
UPDATE acc_transactions SET type = 3 WHERE id IN (SELECT id FROM compta_journal
	WHERE (compte_credit IN ('530', '5112', '5115') OR compte_credit LIKE '512%')
	AND (compte_debit IN ('530', '5112', '5115') OR compte_debit LIKE '512%'));

-- Dettes
UPDATE acc_transactions SET type = 4 WHERE id IN (SELECT id FROM compta_journal WHERE compte_debit LIKE '6%' AND compte_credit LIKE '4%');

-- Créances
UPDATE acc_transactions SET type = 5 WHERE id IN (SELECT id FROM compta_journal WHERE compte_credit LIKE '7%' AND compte_debit LIKE '4%');

-- Création des lignes associées aux mouvements
INSERT INTO acc_transactions_lines (id_transaction, id_account, debit, credit, reference, id_analytical)
	SELECT id, (SELECT id FROM acc_accounts WHERE code = compte_credit), 0, CAST(REPLACE(montant * 100, '.0', '') AS INT), numero_cheque,
	CASE WHEN id_projet IS NOT NULL THEN (SELECT id FROM acc_accounts WHERE code = '99' || substr('0000' || id_projet, -4)) ELSE NULL END
	FROM compta_journal;

INSERT INTO acc_transactions_lines (id_transaction, id_account, debit, credit, reference, id_analytical)
	SELECT id, (SELECT id FROM acc_accounts WHERE code = compte_debit), CAST(REPLACE(montant * 100, '.0', '') AS INT), 0, numero_cheque,
	CASE WHEN id_projet IS NOT NULL THEN (SELECT id FROM acc_accounts WHERE code = '99' || substr('0000' || id_projet, -4)) ELSE NULL END
	FROM compta_journal;

-- Recopie des descriptions de catégories dans la table des comptes, et mise des comptes en signets
-- +Fix éventuels types qui ne correspondent pas à leur type… (@Fred C.) (... a.position = X)
-- Revenus
UPDATE acc_accounts SET type = 6, description = (SELECT description FROM compta_categories WHERE compte = acc_accounts.code)
	WHERE id IN (SELECT a.id FROM acc_accounts a INNER JOIN compta_categories c ON c.compte = a.code AND c.type = 1 AND a.position = 5);

-- Dépenses
UPDATE acc_accounts SET type = 5, description = (SELECT description FROM compta_categories WHERE compte = acc_accounts.code)
	WHERE id IN (SELECT a.id FROM acc_accounts a INNER JOIN compta_categories c ON c.compte = a.code AND c.type = -1 AND c.compte NOT LIKE '4%' AND a.position = 4);

-- Tiers
UPDATE acc_accounts SET type = 4, description = (SELECT description FROM compta_categories WHERE compte = acc_accounts.code)
	WHERE id IN (SELECT a.id FROM acc_accounts a INNER JOIN compta_categories c ON c.compte = a.code AND c.type = -1 AND c.compte LIKE '4%');

-- Recopie des exercices, mais la date de fin ne peut être nulle
INSERT INTO acc_years (id, label, start_date, end_date, closed, id_chart)
	SELECT id, libelle, debut, CASE WHEN fin IS NULL THEN date(debut, '+1 year') ELSE fin END, cloture, 1 FROM compta_exercices;

-- Recopie des catégories, on supprime la colonne id_cotisation_obligatoire
INSERT INTO membres_categories
	SELECT id, nom, droit_wiki, droit_membres, droit_compta, droit_inscription, droit_connexion, droit_config, cacher FROM membres_categories_old;

DROP TABLE membres_categories_old;

-- Transfert des rapprochements
UPDATE acc_transactions_lines SET reconciled = 1 WHERE id_transaction IN (SELECT id_operation FROM compta_rapprochement);

--------- MIGRATION COTISATIONS ----------

-- A edge-case where the end date is after the start date, let's fix it…
UPDATE cotisations SET fin = debut WHERE fin < debut;
UPDATE cotisations SET duree = NULL WHERE duree = 0;

INSERT INTO services SELECT id, intitule, description, duree, debut, fin FROM cotisations;

INSERT INTO services_fees (id, label, amount, id_service, id_account, id_year)
	SELECT id, intitule, CASE WHEN montant IS NOT NULL THEN CAST(montant*100 AS integer) ELSE NULL END, id,
		(SELECT id FROM acc_accounts WHERE code = (SELECT compte FROM compta_categories WHERE id = id_categorie_compta)),
		(SELECT MAX(id) FROM acc_years WHERE closed = 0)
	FROM cotisations;

INSERT INTO services_users SELECT cm.id, cm.id_membre, cm.id_cotisation,
	cm.id_cotisation,
	1,
	NULL,
	cm.date,
	CASE
		WHEN c.duree IS NOT NULL THEN date(cm.date, '+'||c.duree||' days')
		WHEN c.fin IS NOT NULL THEN c.fin
		ELSE NULL
	END
	FROM cotisations_membres cm
	INNER JOIN cotisations c ON c.id = cm.id_cotisation;

INSERT INTO services_reminders SELECT * FROM rappels;
INSERT INTO services_reminders_sent SELECT id, id_membre, id_cotisation,
	CASE WHEN id_rappel IS NULL THEN (SELECT MAX(id) FROM rappels) ELSE id_rappel END, date
	FROM rappels_envoyes
	WHERE id_rappel IS NOT NULL
	GROUP BY id_membre, id_cotisation, id_rappel;

-- Recopie des opérations par membre, mais le nom a changé pour acc_transactions_users, et il faut valider l'existence du membre ET du service
INSERT INTO acc_transactions_users
	SELECT a.* FROM membres_operations_old a
	INNER JOIN membres b ON b.id = a.id_membre
	INNER JOIN services_users c ON c.id = a.id_cotisation;

DROP TABLE cotisations;
DROP TABLE cotisations_membres;
DROP TABLE rappels;
DROP TABLE rappels_envoyes;

-- Suppression inutilisées
DROP TABLE compta_rapprochement;
DROP TABLE compta_journal;
DROP TABLE compta_categories;
DROP TABLE compta_comptes;
DROP TABLE compta_exercices;
DROP TABLE membres_operations_old;

DROP TABLE compta_projets;
DROP TABLE compta_comptes_bancaires;
DROP TABLE compta_moyens_paiement;

INSERT INTO acc_charts (country, code, label) VALUES ('FR', 'PCA2018', 'Plan comptable associatif 2018');

CREATE TEMP TABLE tmp_accounts (code,label,description,position,type);

.import charts/fr_2018.csv tmp_accounts

INSERT INTO acc_accounts (id_chart, code, label, description, position, type) SELECT
	(SELECT id FROM acc_charts WHERE code = 'PCA2018'),
	code, label, description,
	CASE position
		WHEN 'Actif' THEN 1
		WHEN 'Passif' THEN 2
		WHEN 'Actif ou passif' THEN 3
		WHEN 'Charge' THEN 4
		WHEN 'Produit' THEN 5
		ELSE 0
	END,
	CASE type
		WHEN 'Banque' THEN 1
		WHEN 'Caisse' THEN 2
		WHEN 'Attente d''encaissement' THEN 3
		WHEN 'Tiers' THEN 4
		WHEN 'Dépenses' THEN 5
		WHEN 'Recettes' THEN 6
		WHEN 'Analytique' THEN 7
		WHEN 'Bénévolat' THEN 8
		WHEN 'Ouverture' THEN 9
		WHEN 'Clôture' THEN 10
		WHEN 'Résultat excédentaire' THEN 11
		WHEN 'Résultat déficitaire' THEN 12
		ELSE 0
	END
	FROM tmp_accounts;

DROP TABLE tmp_accounts;