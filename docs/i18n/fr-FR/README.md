<!-- Source: README.md; SHA256: 6972a6c9ac754cd60e63c5caf971451ea8fe6e279eef757d886e1a168d3eef01 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_redfish

Un plugin pour Cacti 1.2 qui intègre dans Cacti la consommation électrique des serveurs, la température d'entrée d'air, l'état des ventilateurs et l'état de santé des alimentations depuis les contrôleurs de gestion de carte mère (BMC) sous forme d'équipements, de sources de données et de graphiques ordinaires, via Redfish avec un repli optionnel en lecture seule vers IPMI et des cumuls de puissance à l'échelle de la baie. Thold et Maint assurent les alertes et leur suppression via des sources de données Cacti standard.

> **Statut : en cours de développement, aucune version publiée pour l'instant (`0.1.0-dev`).** Ce plugin n'est pas affilié au projet Cacti. Il s'installe sur Cacti 1.2.31 ou version ultérieure sous PHP 8.2 ou version ultérieure et implémente le collecteur Redfish, le coffre chiffré d'identifiants, le provisionnement d'équipements et de graphiques Cacti, le repli optionnel en lecture seule vers IPMI, le cumul de puissance de baie, l'affichage des fenêtres Cacti Maint, la réplication et les points de terminaison d'agent pour collecteurs de données distants, le fournisseur CasC et la CLI opérateur, les capacités MCP ainsi que les vérifications diagnostiques Doctor. L'intégration continue teste des fixtures, le DMTF Redfish Mockup Server (maquettes de sous-système, héritées uniquement et HPE iLO Gen11) et Thold v1.8.2 avec Maint v1.3 ; aucun véritable BMC physique ni parc pilote n'a encore été qualifié. La conception et les recherches se trouvent dans [docs/PLAN.md](../../PLAN.md), [docs/research/](../../research/) et [docs/decisions/](../../decisions/).

## Capacités implémentées

### Collecteur principal, coffre et objets Cacti

- **Cycle de vie et schéma.** Installation, mise à niveau, désactivation et désinstallation via l'API de plugins de Cacti (`Schema::VERSION = 3`, 12 tables). Les tables sont conservées lors de la désinstallation sauf si *Keep Plugin Tables on Uninstall* est décoché. Les points d'entrée sont analysables sous PHP 8.0 et refusent PHP inférieur à 8.2 ou l'absence de `ext-openssl` avec un message d'erreur explicite.
- **Registre et permissions.** Domaines *Redfish View* (`redfish_health.php`), *Redfish Operator* (`redfish_operator.php`) et *Redfish Admin* (`redfish_bmcs.php`, `redfish_credentials.php`), ainsi que les paramètres dans **Configuration > Settings > Redfish**. Le registre des BMC prend en charge l'importation CSV (identifiants référencés par nom ; les mots de passe dans les fichiers d'importation sont refusés) et l'exportation JSON. Les enregistrements BMC et les identifiants respectent les permissions d'équipements de Cacti.
- **Coffre chiffré d'identifiants.** Les mots de passe sont stockés chiffrés avec AES-256-GCM dans `plugin_redfish_secret`. La clé réside hors de la base de données (`/etc/cacti/redfish-vault.key` par défaut) sous forme de 64 caractères hexadécimaux (`openssl rand -hex 32`) ou 32 octets bruts, appartenant à l'utilisateur du poller, illisible par les autres utilisateurs, dans un répertoire accessible en écriture uniquement par root ou cet utilisateur. Une phrase secrète imprimable de 32 caractères est refusée. **Sauvegardez le fichier de clé avec la base de données** ; sans lui, les mots de passe stockés devront être saisis de nouveau.
- **TLS et client Redfish en lecture seule.** La vérification TLS est activée par défaut via le magasin d'autorités de certification système, un lot d'AC par BMC ou une empreinte SPKI SHA-256 ; la désactivation par BMC reste visible sous forme d'avertissement de santé et d'avertissement de contrôle Doctor. Le client autorise uniquement `GET` ainsi que la connexion (`POST`) et la déconnexion (`DELETE`) de session Redfish, avec un repli optionnel vers Basic par BMC lorsque les sessions sont indisponibles. La découverte privilégie les nouvelles ressources Redfish de sous-systèmes et de capteurs et se replie sur les ressources obsolètes `Power` et `Thermal`.
- **Exécution du collecteur.** `poller_redfish.php` démarre depuis `poller_bottom`, exécute un processus maître par poller via le registre de processus de Cacti avec un nombre configurable de processus travailleurs (`redfish_workers`), acquiert des baux par BMC, applique un budget de temps (`redfish_collector_budget`), applique un recul exponentiel et nettoie la session précédente du plugin lui-même après un plantage.
- **Modèles, équipements et graphiques.** Importe `templates/redfish-templates.xml` par empreinte et copie trois fichiers XML de requête de données du serveur de scripts (`redfish_fans.xml`, `redfish_temperatures.xml`, `redfish_psus.xml`) dans `<cacti>/resource/script_server/` sans écraser les fichiers modifiés par l'utilisateur. **Create Device** et **Add Graphs** exigent la confirmation d'un plan de provisionnement, attachent les requêtes à un équipement serveur mappé ou créent un équipement « Redfish BMC » sous `.invalid` avec les vérifications de disponibilité désactivées, et lient chaque graphique Redfish aux graphiques Redfish de l'équipement via `graph_buttons`.
- **Entrées du serveur de scripts uniquement en cache.** `scripts/ss_redfish_members.php` fournit les relevés par composant de ventilateur, température et alimentation, et `scripts/ss_redfish_summary.php` fournit les métriques « Redfish Node Summary » (puissance, température d'entrée et maximale, nombre de ventilateurs et d'alimentations défaillants, état de santé du nœud et ancienneté de la dernière lecture valide). Les appels du serveur de scripts lisent uniquement le cache local et ne contactent jamais un BMC. Les relevés manquants, périmés ou de composants disparus renvoient `U` (jamais `0`), tandis que `age` signale toujours une valeur afin que les seuils détectent les BMC silencieux.

**Lorsque les composants d'un BMC changent** (par exemple un ventilateur remplacé ou une mise à jour de micrologiciel qui renomme des capteurs), relancez **Add Graphs**. Un ventilateur ou une alimentation précédemment présent qui devient absent est comptabilisé dans `fans_bad` ou `psus_bad` jusqu'à acceptation ; un emplacement qui n'a jamais été peuplé ne l'est pas. Les composants disparus conservent leurs sources de données et graphiques existants, qui lisent `U` par la suite jusqu'à suppression manuelle. La suppression d'un équipement Cacti supprime le mappage de son BMC et son affectation à une baie tout en conservant le BMC et le cache ; la suppression d'un BMC ou la désinstallation du plugin conserve les équipements et graphiques Cacti.

### Repli optionnel vers IPMI

- **Activation volontaire par BMC.** Désactivé par défaut (`protocols` vaut `redfish` par défaut ; l'activation du repli IPMI définit `redfish,ipmi`).
- **Déclenchement ciblé du repli.** S'exécute uniquement lorsque la collecte Redfish échoue avec une classe d'échec éligible ; ne se déclenche jamais sur `AuthRejected`, `CredentialUnavailable`, `AddressRefused`, `OperationRefused` ou `Deadline`.
- **Exécution sécurisée.** Invoque `ipmitool` sur `-I lanplus` via `proc_open()` sous forme de tableau sans shell, avec un délai d'exécution horloge borné et une limite de sortie de 256 Kio. Les mots de passe sont transmis via `IPMITOOL_PASSWORD` (`-E`) ou un fichier temporaire en mode 0600 (`-f`) supprimé dans `finally`, jamais sur `argv` de la ligne de commande.
- **Liste blanche de commandes en lecture seule.** Seules cinq sous-commandes en lecture seule sont autorisées : `dcmi power reading`, `sdr type Temperature`, `sdr type Fan`, `sdr type Power Supply` et `chassis status`. Les commandes de modification sont refusées.
- **Sortie normalisée et avertissement de posture.** `IpmiNormalizer` mappe les relevés vers le même modèle `Observation`/`Member`/`Reading`, en émettant `U` pour les capteurs manquants ou inanalysables. L'activation du repli IPMI déclenche un avertissement sur la page de santé et dans le contrôle Doctor `redfish.bmc_security`, rappelant aux opérateurs de restreindre UDP 623 à un VLAN d'administration.

### Cumul de puissance de baie

- **Registre des baies.** La version 3 du schéma ajoute `plugin_redfish_rack` (`(site_id, name)` unique, `power_budget_watts` optionnel, `min_coverage_pct` par défaut à `80` et notes) ainsi que `plugin_redfish_rack_member` (au plus une affectation de baie par `host_id`, `bmc_id` et `unit_position` optionnels, automatiquement supprimée lors de la suppression d'un équipement Cacti).
- **Totaux protégés par la couverture.** `RackPowerSummary` additionne les relevés récents `power_watts` des membres depuis le cache local et calcule l'utilisation du budget et la marge disponible. Lorsque la couverture des membres qui répondent passe sous `min_coverage_pct`, `power_watts`, `utilization_pct` et `headroom_watts` renvoient `U` afin que les pannes du collecteur n'apparaissent jamais comme une baisse de puissance de la baie.
- **Entrée serveur de scripts prête pour Thold.** `scripts/ss_redfish_rack.php` (`ss_redfish_rack(<rack_id>)`) renvoie `power_watts`, `power_budget_watts`, `utilization_pct`, `headroom_watts`, `members_total`, `members_reporting` et `coverage_pct` depuis le cache local uniquement.

### Intégration Cacti Maint

- Les seuils Thold sur les sources de données Redfish déclenchent des alertes et Cacti Maint les supprime sans aucune modification de l'un ou l'autre plugin.
- `MaintWindow` vérifie les planifications Maint actives pour chaque hôte Cacti mappé (planifications ponctuelles `mtype=1` et récurrentes `mtype=2`, plus `plugin_maint_check_cacti_host()` lorsqu'elle est chargée) et signale la maintenance active sur la page de santé Redfish (`[MAINT]`), dans la sortie `cli/redfish.php health` et dans la capacité `redfish-get-health`.

### Collecteurs de données distants

- `INFO` déclare `remote_collect:1`. Les BMC acceptent tout `poller_id >= 1`, et `poller_redfish.php --poller=N` collecte uniquement les BMC activés affectés à ce poller.
- **Hook `replicate_out`.** Réplique uniquement les tables de configuration non secrètes ciblées sur le poller distant via `replicate_out_table()` : `plugin_redfish_bmc`, les métadonnées de `plugin_redfish_credential` (sans texte chiffré), `plugin_redfish_rack` et `plugin_redfish_rack_member`. `plugin_redfish_secret` n'est pas répliquée et les fichiers de clé du coffre restent strictement locaux sur chaque poller.
- **Hook `remote_agent`.** Expose les actions en lecture seule `redfish_health` et `redfish_discovery` acceptant uniquement les paramètres numériques `poller_id` et `bmc_id`. Les paramètres inattendus sont refusés et aucun identifiant ni secret n'est renvoyé.

### Hooks d'écosystème (CasC, capacités MCP et Doctor)

- **Fournisseur CasC (`casc_register_providers`).** Enregistre le fournisseur `redfish` (version de schéma `1`) avec exportation YAML et XML en PHP pur, analyse (avec refus XXE), validation, planification de diff et application. Les identifiants n'apparaissent que sous forme de références de coffre (`vault:redfish:<name>`) ; les clés secrètes brutes (`pass`, `secret`, `token`, `private_key`, `vault_key`, `auth_token`) sont rejetées. L'application exige un `PLAN_HASH` SHA-256 exact.
- **API MCP / Abilities (`abilities_register`).** Enregistre trois capacités en lecture seule conditionnées par le domaine Redfish View (`redfish_health.php`) et les permissions d'équipements Cacti, ne renvoyant aucun identifiant :
  - `redfish-list-bmcs` (`poller_id` optionnel)
  - `redfish-get-rack-power` (`rack_id` optionnel)
  - `redfish-get-health` (`bmc_id` optionnel)
- **Vérifications Doctor (`doctor_register_checks`).** Enregistre cinq vérifications diagnostiques locales en lecture seule qui ne contactent jamais un BMC et ne renvoient aucune matière de clé :
  - `redfish.schema` : actualité de la version du schéma
  - `redfish.vault_key` : lisibilité et permissions de la clé du coffre
  - `redfish.collector_runs` : budget et fraîcheur du collecteur du poller
  - `redfish.bmc_security` : BMC activés avec TLS non vérifié ou repli IPMI
  - `redfish.rack_power` : conformité au budget de puissance de baie et plancher de couverture

## CLI

Exécuter depuis le répertoire racine de Cacti avec PHP 8.2 ou version ultérieure :

```sh
# Operator and CasC CLI (reads --file=PATH or stdin for validate, plan, and apply)
php plugins/redfish/cli/redfish.php export --format=yaml|xml
php plugins/redfish/cli/redfish.php validate [--file=PATH] [--format=yaml|xml]
php plugins/redfish/cli/redfish.php plan [--file=PATH] [--format=yaml|xml]
php plugins/redfish/cli/redfish.php apply [--file=PATH] [--format=yaml|xml] --confirm=<PLAN_HASH>
php plugins/redfish/cli/redfish.php health --format=text|json

# Collector entry point (normally started by poller_bottom)
php plugins/redfish/poller_redfish.php [--poller=N] [--force] [--debug]

# Rack power summary script server entry point
php plugins/redfish/scripts/ss_redfish_rack.php <rack_id>
```

## Rôles d'accès

| Domaine | Accès |
| --- | --- |
| Redfish View | Pages de surveillance existantes. |
| Redfish Operator | Surveillance plus la page Operations et les acquittements personnels de triage. |
| Redfish Admin | Accès Operator plus la configuration existante et les actions d'administration. |

Après avoir copié les fichiers de mise à niveau, ouvrez la page Plugins en tant qu'administrateur pour enregistrer les domaines. Attribuez explicitement le domaine Operator sous **Configuration > Users** ou aux groupes d'utilisateurs. Les mises à niveau conservent les identifiants de domaine et les droits existants pour View et Admin. L'accès Admin hérite de l'accès Operator, et l'accès Operator hérite de l'accès View à chaque requête ; cela n'ajoute pas de droits stockés aux utilisateurs ni aux groupes. Les paramètres du plugin exigent également l'accès Redfish Admin en plus de la permission Settings de Cacti.

Les permissions d'équipements Cacti limitent les lignes et actions disponibles pour chaque utilisateur ; les objets non mappés requièrent un administrateur. Les opérateurs peuvent lever une mise en pause de collecte sur un BMC autorisé pour la prochaine exécution planifiée. Les identifiants, la configuration, le mappage, la suppression et les autres modifications administratives restent dans les pages d'administration. Les acquittements ne durent que pendant la session en cours de l'utilisateur, au maximum une heure et tant que la même observation reste fraîche. Les observations nouvelles ou modifiées les invalident. Ils ne marquent jamais une mesure en échec comme saine ni ne suppriment les alertes.

## Pourquoi

Chaque serveur possède un BMC qui remonte l'état électrique et thermique indépendamment du système d'exploitation, de sorte qu'il continue de répondre lorsque l'OS est arrêté. Ces données servent au suivi de capacité et de coûts, à la détection précoce d'une défaillance de ventilateur ou d'alimentation et, en calcul haute performance, aux décisions de plafonnement électrique. Le plugin n'est pas spécifique au HPC.

Cacti couvre une partie de ce besoin aujourd'hui :

- **Paquets SNMP constructeurs.** La branche 1.2.x fournit des modèles Dell iDRAC et HPE iLO qui nécessitent un agent SNMP et dépendent du constructeur ; les recherches du jalon 1 ont montré qu'aucun des deux ne remonte la consommation électrique en watts ([docs/research/overlap.md](../../research/overlap.md)).
- **Un type d'identifiant sans collecteur.** plugin_credentials définit un identifiant « IPMI / Redfish (BMC) » mais ne lit aucun BMC.
- **Aucune vue à l'échelle de la baie.** Le cœur de Cacti ne dispose d'aucun objet baie ; un équipement n'a qu'un champ texte libre `location`.

Le plugin interroge les BMC hors bande par rapport au cycle de collecte de Cacti sur le poller principal ou des collecteurs de données distants, met en cache les relevés normalisés et les expose via des requêtes de données du serveur de scripts, des synthèses de nœud et des synthèses de puissance de baie. Il réutilise les graphiques, permissions, Thold et Maint de Cacti et ne modifie pas l'état des BMC.

## Périmètre et statut

| Phase | Périmètre | Jalons | Statut |
|---|---|---|---|
| 0, recherche | Matrice constructeurs, confirmation du comportement amont sur de vrais BMC, coût de collecte, prototype de requête de données | [M1](https://github.com/somethingwithproof/plugin_redfish/milestone/1) | Terminé, en attente de captures de micrologiciels sur de vrais BMC |
| 1, observation | Registre BMC, identifiants, TLS, collecteur Redfish, relevés normalisés, équipements, graphiques par index, alertes Thold, intégration Maint, page de santé | [M2](https://github.com/somethingwithproof/plugin_redfish/milestone/2), [M3](https://github.com/somethingwithproof/plugin_redfish/milestone/3), [M4](https://github.com/somethingwithproof/plugin_redfish/milestone/4) | Implémenté ; qualification du parc pilote M4 en attente |
| 2, ipmi | Repli IPMI en lecture seule (`lanplus`) via `ipmitool`, désactivé par défaut | [M5](https://github.com/somethingwithproof/plugin_redfish/milestone/5) | Implémenté |
| 3, baies | Registre des baies, totaux de puissance de baie avec plancher de couverture, marge budgétaire, `ss_redfish_rack.php` | [M6](https://github.com/somethingwithproof/plugin_redfish/milestone/6) | Implémenté |
| 4, distant et écosystème | Collecteurs de données distants (`replicate_out`, `remote_agent`), fournisseur CasC et CLI, capacités MCP, vérifications Doctor | [M7](https://github.com/somethingwithproof/plugin_redfish/milestone/7) | Implémenté |

Hors périmètre : définition de plafonds de puissance ou toute autre configuration de BMC, contrôle de l'alimentation, mise à jour de micrologiciel, accès console, moteur d'alertes propre au plugin et intégration d'ordonnanceur.

## Cibles de compatibilité

| Composant | Cible |
|---|---|
| Cacti | 1.2.31 ou version ultérieure, sans correctif du cœur |
| Base de données | MariaDB 10.2 ou MySQL 5.7 ou version ultérieure : un seul BMC par équipement est garanti par une clé unique sur une colonne générée |
| PHP | 8.2 ou version ultérieure ; les points d'entrée restent analysables sous 8.0 afin qu'un moteur non pris en charge reçoive un refus plutôt qu'une interface Cacti cassée |
| Extensions PHP | `ext-openssl` (requise par Cacti 1.2.31) ; pas d'`ext-curl` et aucune dépendance d'exécution Composer |
| Outils optionnels | `ipmitool` sur les pollers collectant des BMC dont le repli IPMI est activé |
| BMC | Dell iDRAC, HPE iLO, Lenovo XCC et OpenBMC, selon la plage de micrologiciel ; voir [la matrice constructeurs](../../research/vendor-matrix.md) |
| Redfish | Nouvelles ressources de sous-système et de capteur d'abord, ressources obsolètes `Power` et `Thermal` en repli |
| Thold, Maint | Aucune modification requise (la CI exerce Thold v1.8.2 et Maint v1.3) |

## Conception

- [docs/PLAN.md](../../PLAN.md) est le plan complet : architecture, faits amont dont elle dépend, modèle de mesure, frontières de sécurité, arithmétique de performance, tests et risques.
- [docs/decisions/](../../decisions/) rassemble les enregistrements de décisions d'architecture pour les choix déjà effectués.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) associe chaque identifiant d'exigence à son ticket.

```mermaid
flowchart LR
    BMC["BMC : Redfish HTTPS / IPMI lanplus optionnel"] --> C["Collecteur sur poller principal ou distant"]
    C --> CACHE[("Cache de relevés normalisés")]
    CACHE --> SS["Serveur de scripts : composants, synthèse nœud, synthèse baie"]
    SS -->|poller_output| T["Thold et Maint"]
    SS --> G["Graphiques et permissions Cacti"]
    CACHE --> ECO["CLI, CasC, capacités MCP, Doctor"]
```

## Sécurité

Les identifiants BMC contrôlent la gestion hors bande des serveurs. La conception garde les secrets hors des lignes de commande, journaux, exportations CasC, charges utiles d'agent distant, réponses MCP et pages web ; chiffre les mots de passe stockés avec AES-256-GCM à l'aide d'un fichier de clé de coffre local par poller ; vérifie TLS par défaut ; exécute `ipmitool` depuis un tableau explicite d'arguments avec des sous-commandes en lecture seule autorisées ; et utilise des comptes BMC en lecture seule. Voir [docs/PLAN.md](../../PLAN.md#security) et [SECURITY.md](../../../SECURITY.md).

## Guide de l'opérateur

Consultez [docs/operator-guide.md](../../operator-guide.md) pour les prérequis d'installation, la configuration du coffre et de TLS, l'enregistrement des BMC, la création de graphiques, les intervalles d'alerte, la sauvegarde et la gestion des pannes. Le guide ne déclare aucune gamme réelle de micrologiciel constructeur comme prise en charge tant qu'une capture expurgée issue de ce micrologiciel n'a pas passé la suite de tests.

## Développement et tests

L'intégration continue ([.github/workflows/plugin-ci-workflow.yml](../../../.github/workflows/plugin-ci-workflow.yml)) analyse la syntaxe des points d'entrée sous PHP 8.0, exécute les suites Pest unitaires (`phpunit.xml`) et d'intégration de cycle de vie (`phpunit-integration.xml`) ainsi que PHPStan niveau 9 de PHP 8.2 à 8.5 face à Cacti `release/1.2.31` et `1.2.x`, vérifie le refus sous PHP 8.1, exerce le DMTF Redfish Mockup Server (maquettes de sous-système, héritées uniquement et HPE iLO Gen11) et Thold v1.8.2 / Maint v1.3 de bout en bout, et contrôle les catalogues gettext, les workflows et les licences REUSE.

Vérifications locales avant de pousser :

```sh
sh tests/lint-workflows.sh
sh locales/build_gettext.sh --check
CACTI_PATH=/path/to/cacti phpstan analyse -c phpstan.neon --no-progress
```

Exécutez Pest avec `--configuration=phpunit.xml` pour les tests unitaires et de règles de code source, ou avec `--configuration=phpunit-integration.xml` avec `CACTI_PATH` et `REDFISH_TEST_DATABASE=disposable` sur une base Cacti jetable pour les tests d'intégration de cycle de vie.

## Suivi du travail

- **Les jalons (Milestones)** suivent les jalons du plan. Chacun dispose d'un ticket épique listant ses sous-tickets et ses preuves de sortie.
- **Les tickets (Issues)** sont typés par étiquette : `type: requirement`, `type: research`, `type: decision`, `type: epic`. Les tickets d'exigence énoncent des exigences numérotées et testables (`REQ-AREA-NNN`) ainsi que des critères d'acceptation.
- **Les étiquettes** portent aussi `area:`, `phase:`, `priority:` et `status:`. Voir [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Le [tableau de projet](https://github.com/users/somethingwithproof/projects/12) présente tous les tickets à travers les jalons.

## Contribution

Lisez [CONTRIBUTING.md](../../../CONTRIBUTING.md). Les commits nécessitent une signature DCO (`Signed-off-by`). Les discussions de conception ont lieu dans les tickets ; les décisions ouvertes portent l'étiquette `type: decision`. Les preuves provenant de véritables BMC constituent la contribution la plus utile actuellement ; expurgez les adresses, numéros de série et identifiants avant de publier toute capture.

## Licence

GNU General Public License v2.0, la même licence que Cacti et ses plugins. Voir [LICENSE](../../../LICENSE).
