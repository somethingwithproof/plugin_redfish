<!-- Source: README.md; SHA256: 7b4e83a58a1adc844cb0615ef30e95476cba2851f96bb454d2a816fb2774724d -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_redfish

Un plugin Cacti 1.2 qui intègre la consommation électrique des serveurs, la température d'entrée, l'état des ventilateurs et l'état de santé des alimentations depuis les contrôleurs de gestion de carte mère (BMC) dans Cacti en tant qu'équipements, sources de données et graphes ordinaires, via Redfish avec un repli optionnel vers IPMI. Thold et Maint déclenchent ensuite des alertes et les inhibent, et une phase ultérieure ajoutera les totaux de puissance au niveau de la baie.

> **Statut : en développement, aucune version publiée pour le moment (0.1.0-dev).** Les jalons 2 (socle) et 3 (objets Cacti et alertes) sont fusionnés sur `main`. Ce plugin n'est pas affilié au projet Cacti. Il s'installe sur Cacti 1.2.31 sous PHP 8.2 ou version ultérieure, enregistre les BMC et les identifiants, lit la puissance, les températures, les ventilateurs et l'état de santé des alimentations de chaque BMC via Redfish dans son propre cache, et affiche l'état de santé de la collecte. Il ajoute des équipements Cacti, des graphes par composant et de synthèse, ainsi que la prise en charge de Thold et Maint via des sources de données ordinaires. L'intégration continue (CI) a testé un BMC simulé construit à partir de jeux de données rédigés à la main et du serveur de simulation DMTF Redfish ; aucun BMC réel ni parc pilote n'a encore été qualifié. La conception et les travaux de recherche se trouvent dans [docs/PLAN.md](../../PLAN.md), [docs/research/](../../research) et [docs/decisions/](../../decisions).

### Ce que livre le jalon 2

- Installation, mise à niveau, désactivation et désinstallation via l'API des plugins de Cacti, avec conservation des tables lors de la désinstallation sauf si le paramètre est effacé. Sur une version de PHP antérieure à 8.2, le plugin refuse l'installation ou l'activation et en indique la raison.
- Domaines (realms) « Redfish View » (page d'état de santé), « Redfish Operator » (page des opérations) et « Redfish Admin » (pages des BMC et des identifiants), ainsi que les paramètres sous Configuration > Settings > Redfish.
- Un registre des BMC avec import CSV et export JSON. Les fichiers d'import ne doivent contenir aucun mot de passe ; les identifiants sont référencés par leur nom.
- Identifiants stockés chiffrés avec AES-256-GCM. La clé est un fichier en dehors de la base de données, `/etc/cacti/redfish-vault.key` par défaut, contenant 64 caractères hexadécimaux (`openssl rand -hex 32`) ou exactement 32 octets aléatoires, appartenant à l'utilisateur du poller, non lisible par les autres utilisateurs, dans un répertoire où seul l'utilisateur du poller ou root peut écrire. Une clé de 32 caractères imprimables est refusée en tant que phrase secrète. **Sauvegardez le fichier de clé avec la base de données** ; sans lui, chaque mot de passe enregistré devra être saisi à nouveau.
- Vérification TLS activée par défaut, avec un bundle d'autorités de certification (CA) ou une empreinte SPKI SHA-256 par BMC. Une désactivation journalisée de la vérification par BMC reste affichée sur la page d'état de santé sous forme d'avertissement.
- Un client Redfish en lecture seule : requêtes `GET` ainsi que l'ouverture et la fermeture de session uniquement, vérifiées par rapport à une liste blanche avant l'envoi de toute requête.
- Un collecteur lancé depuis `poller_bottom` pour les BMC rattachés au poller principal, avec baux et échéances par BMC, temporisation exponentielle (backoff) et nettoyage après plantage de l'unique session enregistrée par le plugin. Les collecteurs distants ne sont pas encore pris en charge ; ils nécessitent un choix explicite quant à la manière dont le collecteur accède à la base de données et à la clé (phase 4).
- Les enregistrements de BMC et les identifiants suivent les permissions sur les équipements Cacti : un utilisateur Redfish Admin ne voit, ne modifie et n'associe que les BMC des équipements qu'il est autorisé à voir, et uniquement les identifiants qu'aucun BMC d'un équipement masqué n'utilise.

### Ce qu'ajoute le jalon 3

- Modèles fournis sous forme de XML de modèles Cacti dans `templates/` et importés avec l'outil d'importation natif de Cacti lors de l'installation et de la mise à niveau, avec correspondance par empreinte (hash), de sorte qu'une importation met à jour les objets du plugin sans rien ajouter en double. Les trois fichiers de requête de données sont copiés dans `<cacti>/resource/script_server/` avec une empreinte enregistrée ; le plugin ne remplace jamais un fichier présent à cet emplacement s'il ne l'a pas lui-même écrit, et la page d'état de santé avertit les administrateurs lorsque les modèles ou un fichier de requête ne sont pas en place. **Reinstall the Redfish Templates** sur la page des BMC les importe de nouveau ; cette action nécessite la permission Import Templates de Cacti.
- **Create Device** ou **Add Graphs** sur chaque BMC ([ADR 0015](../../decisions/0015-attach-to-the-server-device-or-create-one-per-bmc.md)). Un BMC associé à l'équipement existant d'un serveur reçoit ses requêtes de données et ses graphes sur cet équipement. Un BMC non associé reçoit un nouvel équipement créé à partir du modèle d'équipement « Redfish BMC », avec les vérifications de disponibilité désactivées et un nom d'hôte sous `.invalid`, afin que Cacti ne contacte jamais le BMC et n'affiche jamais son adresse. Dans les deux cas, un administrateur confirme d'abord un plan listant ce qui sera ajouté. Les deux actions requièrent la permission de gestion des équipements de Cacti ainsi que le rôle Redfish Admin ; un nouvel équipement que son créateur ne pourrait pas voir est immédiatement supprimé.
- Trois requêtes de données script server, « Redfish Fans », « Redfish Temperatures » et « Redfish Power Supplies », indexées par la clé de composant du BMC, avec un graphe par composant : vitesse de ventilateur en pourcentage ou en tr/min (RPM) selon l'unité remontée par le BMC, température, et puissance d'entrée et de sortie des alimentations en watts, chacun accompagné de son code d'état de santé.
- Une entrée « Redfish Node Summary » comprenant la puissance, la température d'entrée, la température maximale, le nombre de ventilateurs et d'alimentations en anomalie, l'état de santé du nœud et l'âge de la dernière lecture valide, représentée sur quatre graphes : puissance, températures, état de santé des composants et ancienneté des données. Les seuils Thold sur ces sources de données déclenchent des alertes et Maint les inhibe, sans aucune modification de l'un ou l'autre plugin.
- Le script server répond uniquement depuis le cache du plugin et ne contacte jamais un BMC. Une valeur manquante, obsolète, exprimée dans une autre unité ou provenant d'un composant disparu vaut `U`, de sorte que les graphes affichent une interruption et jamais zéro ; `age` possède toujours une valeur, si bien qu'un seuil sur celle-ci détecte un BMC qui a cessé de répondre.
- Un lien de graphe depuis chaque graphe Redfish vers les graphes Redfish de l'équipement, affiché uniquement aux utilisateurs auxquels Cacti autorise la visualisation de l'équipement.

**Lorsque les composants d'un BMC changent** (remplacement d'un ventilateur, mise à jour du micrologiciel renommant des capteurs), sélectionnez de nouveau **Add Graphs**. Un ventilateur ou une alimentation qui était présent et qui est désormais absent ou disparu est comptabilisé dans `fans_bad` ou `psus_bad` jusqu'à ce que vous effectuiez cette opération, afin qu'un ventilateur retiré déclenche une alerte ; un emplacement qui n'a jamais été occupé ne l'est pas. Add Graphs accepte les composants que le BMC signale actuellement et Cacti réindexe les requêtes ; un composant disparu conserve sa source de données et son graphe, qui affichent désormais `U`, et un nouveau composant reçoit un graphe. Supprimez manuellement les graphes des composants disparus dès que leur historique n'est plus nécessaire.

La suppression d'un équipement dissocie son BMC tout en conservant le BMC et son cache. La suppression d'un BMC, ou la désinstallation du plugin, conserve son équipement et ses graphes.

## Rôles d'accès

| Domaine | Accès |
| --- | --- |
| Redfish View | Pages de supervision existantes. |
| Redfish Operator | Supervision, page des opérations et acquittements personnels de triage. |
| Redfish Admin | Accès Operator plus la configuration existante et les actions d'administration. |

Après avoir copié les fichiers de mise à niveau, ouvrez la page Plugins en tant
qu'administrateur pour enregistrer le nouveau domaine. Attribuez explicitement le domaine Operator sous **Configuration > Users** ou au niveau des groupes d'utilisateurs.
Les mises à niveau conservent les identifiants de domaine et les droits existants pour View et Admin. L'accès Admin
hérite de l'accès Operator, et l'accès Operator hérite de l'accès View à chaque requête ;
cela n'ajoute pas de droits stockés aux utilisateurs ni aux groupes. Les paramètres du plugin exigent également
l'accès Redfish Admin en plus de la permission Settings de Cacti.

Les permissions sur les équipements Cacti continuent de limiter les lignes et les actions accessibles à chaque utilisateur ; les objets non associés requièrent un administrateur. Les opérateurs peuvent lever une suspension de collecte sur un BMC autorisé pour la prochaine exécution planifiée.
Les identifiants, la configuration, l'association, la suppression et les autres modifications administratives
restent dans les pages d'administration. Les acquittements ne durent que le temps de la session en cours
de l'utilisateur, pendant au plus une heure et uniquement tant que la même observation reste
fraîche. Toute observation nouvelle ou modifiée les invalide. Ils ne marquent jamais une mesure en échec
comme saine et n'inhibent pas les alertes.

## Pourquoi

Chaque serveur dispose d'un BMC qui remonte l'état électrique et thermique indépendamment du système d'exploitation, ce qui lui permet de continuer à répondre même lorsque le système d'exploitation est arrêté. Ces données servent au suivi des capacités et des coûts, à la détection précoce d'une défaillance de ventilateur ou d'alimentation et, en calcul haute performance (HPC), aux décisions de plafonnement électrique (power capping). Le plugin n'est pas spécifique au HPC.

Cacti couvre déjà une partie de ce besoin :

- **Paquets SNMP constructeurs.** La branche 1.2.x inclut des modèles Dell iDRAC et HPE iLO qui nécessitent un agent SNMP et sont propres à chaque constructeur ; les travaux de recherche du jalon 1 ont montré qu'aucun des deux ne remonte la consommation électrique en watts ([docs/research/overlap.md](../../research/overlap.md)).
- **Un type d'identifiant sans collecteur.** plugin_credentials définit un identifiant « IPMI / Redfish (BMC) », mais n'interroge aucun BMC.
- **Aucune vue au niveau de la baie.** Le cœur de Cacti ne comporte pas d'objet baie ; un équipement dispose simplement d'un champ `location` en texte libre.

Le plugin interroge les BMC hors bande par rapport au cycle de collecte de Cacti, met en cache les mesures normalisées et les expose via une requête de données script server ainsi que des sources de données de synthèse. Il réutilise la création de graphes, les permissions, Thold et Maint de Cacti, et ne modifie jamais l'état des BMC. La prise en charge des collecteurs de données distants est prévue pour la phase 4 ; la collecte actuelle s'exécute uniquement sur le poller principal.

## Périmètre prévu

| Phase | Périmètre | Jalons |
|---|---|---|
| 0, recherche | Matrice des constructeurs, confirmation du comportement en amont sur des BMC réels, coût de collecte, prototype de requête de données | [M1](https://github.com/somethingwithproof/plugin_redfish/milestone/1) |
| 1, observer | Registre des BMC, identifiants, TLS, collecteur Redfish, mesures normalisées, équipements, graphes par index, alertes Thold, inhibition Maint, page d'état de santé | [M2](https://github.com/somethingwithproof/plugin_redfish/milestone/2), [M3](https://github.com/somethingwithproof/plugin_redfish/milestone/3), [M4](https://github.com/somethingwithproof/plugin_redfish/milestone/4) |
| 2, ipmi | Repli vers IPMI pour les BMC sans implémentation Redfish exploitable, désactivé par défaut | [M5](https://github.com/somethingwithproof/plugin_redfish/milestone/5) |
| 3, baies | Registre des baies, totaux de puissance par baie avec taux de couverture, marge budgétaire | [M6](https://github.com/somethingwithproof/plugin_redfish/milestone/6) |
| 4, distant | Collecteurs de données distants Cacti pour les réseaux BMC isolés | [M7](https://github.com/somethingwithproof/plugin_redfish/milestone/7) |

Hors périmètre : définition de plafonds de puissance ou toute autre configuration des BMC, contrôle de l'alimentation, mise à jour de micrologiciel, accès console, moteur d'alertes propre au plugin et intégration d'ordonnanceur.

## Cibles de compatibilité

| Composant | Cible |
|---|---|
| Cacti | 1.2.31 ou version ultérieure, sans correctif du cœur |
| Base de données | MariaDB 10.2 ou MySQL 5.7 ou version ultérieure : l'unicité d'un BMC par équipement est assurée par une clé unique sur une colonne générée |
| PHP | 8.2 ou version ultérieure ; `setup.php` reste analysable sous PHP 8.0 afin qu'un environnement non pris en charge reçoive un refus explicite plutôt qu'une interface Cacti cassée |
| BMC | Dell iDRAC, HPE iLO, Lenovo XCC et OpenBMC, selon la plage de micrologiciels ; voir [la matrice des constructeurs](../../research/vendor-matrix.md) |
| Redfish | Nouvelles ressources de sous-systèmes et de capteurs en priorité, ressources obsolètes `Power` et `Thermal` en repli |
| Thold, Maint | Aucune modification requise |

## Conception

- [docs/PLAN.md](../../PLAN.md) constitue le plan complet : architecture, faits amont sur lesquels elle s'appuie (avec sources et hypothèses restant à confirmer), modèle de mesure, frontières de sécurité, calculs de performance, tests et risques.
- [docs/decisions/](../../decisions) regroupe les dossiers de décision d'architecture (ADR) pour les choix déjà arrêtés.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) associe chaque identifiant d'exigence à son ticket.

```mermaid
flowchart LR
    BMC["BMC : Redfish sur HTTPS"] --> C["Collecteur sur le poller Cacti"]
    C --> CACHE[("Cache des mesures normalisées")]
    CACHE --> SS["Requête de données script server"]
    SS -->|poller_output| T["Thold et Maint"]
    SS --> G["Graphes et permissions Cacti"]
    CACHE --> R["Totaux par baie, phase 3"]
```

## Sécurité

Les identifiants BMC donnent accès à l'administration hors bande des serveurs. La conception les maintient hors des lignes de commande, des journaux et des pages web, vérifie TLS par défaut, exécute toute commande externe via un tableau d'arguments avec des sous-commandes sur liste blanche et utilise des comptes BMC en lecture seule. Voir [docs/PLAN.md](../../PLAN.md#security) et [SECURITY.md](../../../SECURITY.md).

## Guide de l'opérateur

Voir [docs/operator-guide.md](../../operator-guide.md) pour les prérequis d'installation, la configuration du coffre-fort et de TLS, l'enregistrement des BMC, la création des graphes, les intervalles d'alerte, la sauvegarde et la gestion des incidents. Ce guide ne déclare aucune gamme de micrologiciels constructeur réelle comme prise en charge tant qu'une capture expurgée issue de ce micrologiciel n'a pas passé la suite de tests.

## Suivi des travaux

- **Jalons** : ils suivent les jalons du plan. Chacun dispose d'un ticket épique listant ses sous-tickets et ses preuves de sortie.
- **Tickets** : ils sont typés par étiquette : `type: requirement`, `type: research`, `type: decision`, `type: epic`. Les tickets d'exigence énoncent des exigences numérotées et testables (`REQ-AREA-NNN`) ainsi que leurs critères d'acceptation.
- **Étiquettes** : elles portent également les préfixes `area:`, `phase:`, `priority:` et `status:`. Voir [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Le [tableau de projet](https://github.com/users/somethingwithproof/projects/12) présente l'ensemble des tickets à travers les jalons.

## Contribution

Lisez [CONTRIBUTING.md](../../../CONTRIBUTING.md). Les commits doivent comporter une signature DCO (sign-off). Les discussions de conception ont lieu dans les tickets ; les décisions ouvertes portent l'étiquette `type: decision`. Les retours d'expérience issus de BMC réels constituent la contribution la plus utile à ce stade ; expurgez les adresses, numéros de série et identifiants avant de publier toute capture.

## Licence

GNU General Public License v2.0, la même licence que Cacti et ses plugins. Voir [LICENSE](../../../LICENSE).
