# Digital by Stella — Réservation visio payante (Stripe + Google Meet)

Cette mise à jour remplace le formulaire Formspree par un système de
réservation : le visiteur choisit un créneau, paie 19 € sur Stripe, et
reçoit automatiquement une invitation Google Calendar avec un lien
Google Meet.

> **Compat** : tant que les clés ne sont pas configurées, le site retombe
> automatiquement sur le formulaire historique (mailto/Formspree). Aucune
> régression côté visiteur.

## Fichiers livrés (`_site-stella-html/`)

| Fichier | Rôle |
| --- | --- |
| `index.html` | Site complet avec section contact refondue. |
| `booking.php` | Backend : créneaux, Stripe Checkout, webhook, création event Google Meet. |
| _retour Stripe_ | Le widget de `index.html` détecte `?session_id` et finalise directement sur la home (pas de page séparée, comme sur elifagency.com). |
| `politique-de-confidentialite.html` | Inchangé. |
| `hero-mobile.mp4` / `kling_*.mp4` | Vidéos hero (inchangées). |
| `booking.config.php.example` | Template de configuration (à copier sur le serveur **HORS web root**, sous le nom `booking.php`). |

## 1. Pré-requis

- Hébergement PHP 8+ avec extension `curl` activée (Hostinger, OVH, Infomaniak — la plupart des hébergements mutualisés).
- Compte Google (Gmail ou Workspace) qui sera propriétaire des rendez-vous.
- Compte Stripe activé (au moins en mode test pour démarrer).
- Domaine HTTPS public (pour le webhook Stripe).

## 2. Application Google Cloud (à faire une fois)

1. Ouvrir <https://console.cloud.google.com/> → créer un projet `digital-by-stella`.
2. **APIs & Services → Library** → activer **Google Calendar API**.
3. **OAuth consent screen** :
   - User Type : *External*.
   - Renseigner nom, email, contact.
   - Ajouter le scope `https://www.googleapis.com/auth/calendar`.
   - Ajouter l'email de Stella en *Test user* (suffit tant que l'app reste en *Testing*).
4. **Credentials → Create credentials → OAuth client ID** :
   - Type : *Web application*.
   - **Authorized redirect URIs** : `https://developers.google.com/oauthplayground`.
   - Récupérer **Client ID** + **Client Secret**.

## 3. Refresh token (une seule fois)

1. Aller sur <https://developers.google.com/oauthplayground>.
2. ⚙️ en haut à droite → cocher *Use your own OAuth credentials* → coller le Client ID / Secret.
3. Dans les scopes, sélectionner **Calendar API v3 → `https://www.googleapis.com/auth/calendar`**.
4. *Authorize APIs* → se connecter avec **le compte Google de Stella** → accepter.
5. *Exchange authorization code for tokens* → copier le **Refresh token**.

Le refresh token reste valide indéfiniment tant que l'app reste publiée
(ou en *Testing* avec l'email de Stella en *Test user*).

## 4. Stripe

1. Dashboard Stripe → **Developers → API keys** → **Secret key** (mode *Test* pour démarrer).
2. **Developers → Webhooks → Add endpoint** :
   - URL : `https://digitalbystella.com/booking.php`
   - Événements : `checkout.session.completed` + `checkout.session.async_payment_succeeded`
   - Copier le **Signing secret** (`whsec_…`).

> Le webhook est essentiel en prod : il garantit la création du RDV même
> si le client ferme l'onglet juste après le paiement.

## 5. Configuration sur le serveur

1. **Uploader** le contenu du dépôt dans le web root (typiquement `public_html/`) :
   `index.html`, `booking.php`, `politique-de-confidentialite.html`, les `.mp4`. **NE PAS uploader** `booking.config.php.example` ni `BOOKING-README.md` (ces deux fichiers servent uniquement pour la doc/le dépôt).
2. **Copier** `booking.config.php.example` **hors du web root** sous le nom `booking.php` :
   `<domaine>/private/booking.php` (typiquement à côté de `public_html/`, pas dedans).
3. **Compléter les valeurs** : `google_client_id`, `google_client_secret`, `google_refresh_token`, `stripe_secret`, `stripe_webhook_secret`, `site_url`.

Exemple d'arborescence Hostinger :
```
/home/u123/
├── domains/
│   └── digitalbystella.com/
│       ├── public_html/        ← fichiers servis publiquement
│       │   ├── index.html
│       │   ├── booking.php     ← code public (lit la config hors web root)
│       │   ├── politique-de-confidentialite.html
│       │   ├── hero-mobile.mp4
│       │   └── kling_*.mp4
│       └── private/
│           └── booking.php     ← copie de booking.config.php.example, complétée avec les clés
```

Vérifier que `https://digitalbystella.com/private/booking.php` **n'est PAS accessible** (le `/private/` est hors web root, donc inatteignable).

## 6. Tester

- Passer Stripe en mode **Test**, utiliser la carte test `4242 4242 4242 4242` (n'importe quelle date/CVC future).
- Ouvrir le site, choisir un créneau, remplir le formulaire, cliquer **Réserver et payer**.
- Après le paiement, on est redirigé vers `merci.html`. Si tout va bien, le RDV apparaît dans le Google Calendar de Stella avec un lien Meet.

## 7. Personnalisations rapides

Dans `private/booking.php`, modifier au besoin :

- `price_cents` : prix en centimes (1900 = 19 €).
- `currency` : `eur`, `usd`…
- `days`, `start_hour`, `end_hour` : disponibilités.
- `lead_hours` : délai minimum (12 h par défaut).
- `horizon_days` : jusqu'à combien de jours dans le futur on propose des créneaux.
- `duration_min` : durée d'un créneau (30 min par défaut).

## 8. Filet de sécurité — mode désactivé

Si `private/booking.php` n'existe pas ou est incomplet, l'endpoint
renvoie `503` et le frontend bascule **automatiquement** sur le
formulaire mailto historique (Formspree). Idem si l'hébergeur ne
supporte pas PHP : le site reste fonctionnel comme avant.

## 9. Architecture rapide

```
visiteur ──▶ index.html
                │ 1. GET booking.php?action=slots
                ▼                                  ┌─ Google Calendar ──┐
              choix créneau                        │   FreeBusy +        │
                │ 2. POST booking.php (action=checkout) │ events.insert       │
                ▼                                  └─────────────────────┘
            Stripe Checkout (paiement)                       ▲
                │                                           │
                ├──▶ 3a. retour visiteur → merci.html       │
                │      GET booking.php?action=finalize ─────┘
                │
                └──▶ 3b. webhook Stripe → POST booking.php (filet de sécurité)
```

Idempotence : un même `stripeSessionId` ne crée qu'**un seul** event
Google Calendar, peu importe qui appelle (visiteur ou webhook).
