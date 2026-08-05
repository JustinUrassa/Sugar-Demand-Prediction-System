# SugarCast

**Sugar demand forecasting and stock recommendation system for Mbeya Markets, Tanzania.**

SugarCast helps sugar traders decide *how much to order and when* by
combining historical sales records with a trained machine learning model,
falling back to a transparent statistical formula whenever there isn't yet
enough data to trust the model. It's a full-stack system — PHP/MySQL API,
a dependency-free vanilla JS frontend with English/Swahili bilingual
support, and a Python/scikit-learn inference service — built as a final
year project and designed to run comfortably on a local XAMPP stack.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-8%2F10.4%2B-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.10%2B-3776AB?logo=python&logoColor=white)
![scikit--learn](https://img.shields.io/badge/scikit--learn-ML-F7931E?logo=scikitlearn&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?logo=javascript&logoColor=black)
![i18n](https://img.shields.io/badge/i18n-EN%20%2F%20SW-0f766e)

---

## Table of contents

- [Overview](#overview)
- [Features](#features)
- [Architecture](#architecture)
- [Tech stack](#tech-stack)
- [Project structure](#project-structure)
- [Getting started](#getting-started)
- [How a demand estimate becomes a recommendation](#how-a-demand-estimate-becomes-a-recommendation)
- [API reference](#api-reference)
- [Internationalization](#internationalization)
- [Design conventions](#design-conventions)
- [Roadmap](#roadmap)
- [License](#license)

---

## Overview

Traders on the Mbeya sugar market face a recurring problem: order too
little stock and you lose sales during a demand spike; order too much and
capital sits idle in unsold inventory. SugarCast addresses this by
turning raw sales history into two concrete outputs:

1. **A demand estimate** for a future date, produced either by a trained
   regression model (once enough continuous sales history exists) or a
   transparent seasonal/price-elasticity formula otherwise.
2. **A stock recommendation** derived from that estimate — how much to
   hold, a risk level, and a plain-language action ("Order X kg
   immediately", "Overstock warning: reduce next order", etc.).

Every prediction records *which* method produced it, so the system is
honest about its own confidence rather than presenting a heuristic guess
as if it were a trained model's output.

## Features

- **Demand analysis** — submit a target date, price, season, and holiday
  flag; get back a predicted quantity with a confidence interval and the
  model that produced it.
- **Automatic stock recommendations** — every prediction immediately
  generates a matching recommendation with a 15% safety buffer, risk
  level, and required action.
- **Sales records management** — full CRUD on the sales ledger, plus bulk
  CSV import.
- **Reports & analytics** — live monthly and yearly breakdowns, a rolling
  12-month performance banner with year-over-year growth, and CSV/PDF
  export, all computed from the database rather than hardcoded.
- **Role-based access** — `admin`, `trader`, and `supplier` roles, with
  admin-only user management (create, edit, deactivate, reset password).
- **Authentication** — signup, login, and a full forgot/reset-password
  email flow with expiring tokens.
- **Profile & avatars** — self-service profile editing and password
  changes; uploaded profile pictures persist server-side and repaint
  instantly across the sidebar, topbar, and dropdown the moment they're
  uploaded.
- **Notifications** — an in-app notification feed for stock and pricing
  alerts.
- **Bilingual UI** — every user-facing string is available in English and
  Swahili with a live language toggle, no page reload required.
- **Light/dark theme** — persisted per browser.
- **Graceful ML degradation** — if Python, its dependencies, or the model
  file are ever unavailable, the app transparently falls back to the
  statistical model instead of breaking.

## Architecture

```
┌─────────────────────┐        ┌──────────────────────┐        ┌───────────────────────┐
│      frontend/       │        │       backend/        │        │          ml/           │
│  HTML/CSS/vanilla JS  │  HTTP  │      PHP + PDO API     │ stdin/ │  Python inference       │
│  (no build step)      │ ─────▶ │  one file per resource │ stdout │  scikit-learn model     │
│                       │  JSON  │                        │ ─────▶ │                        │
└─────────────────────┘        └───────────┬────────────┘        └───────────────────────┘
                                            │
                                            ▼
                                  ┌───────────────────┐
                                  │     database/       │
                                  │  MySQL / MariaDB     │
                                  └───────────────────┘
```

The frontend never talks to Python directly — `predict.php` is the only
caller of the ML bridge, via `proc_open`, so the model can be swapped,
retrained, or temporarily removed without touching the client at all.

## Tech stack

| Layer         | Technology                                              |
|---------------|----------------------------------------------------------|
| Frontend      | HTML5, CSS3 (custom design system, no framework), vanilla JavaScript |
| Backend       | PHP 8 (PDO, sessions, no framework)                       |
| Database      | MySQL / MariaDB                                           |
| ML inference  | Python 3, pandas, scikit-learn, joblib                    |
| Charts        | Chart.js                                                   |
| Local server  | XAMPP (Apache + MariaDB + PHP)                             |

## Project structure

```
sugarcast/
├── frontend/                    Client — plain HTML/CSS/JS, no build step
│   ├── index.html               Login / signup entry point
│   ├── reset-password.html      Forgot/reset password flow
│   ├── pages/                   Everything behind login
│   │   ├── dashboard.html
│   │   ├── data.html            Sales records management
│   │   ├── prediction.html      Run a demand estimate
│   │   ├── recommendation.html  Stock recommendations
│   │   ├── reports.html         Monthly/yearly analytics
│   │   ├── settings.html
│   │   ├── users.html           Admin-only
│   │   └── profile.html
│   └── assets/
│       ├── css/                 index.css, main.css, login.css
│       ├── js/                  app.js, i18n.js, layout.js
│       ├── images/              sugar_bg.jpg
│       └── uploads/avatars/     Profile pictures (created automatically)
│
├── backend/                     Server — PHP API, one file per resource
│   ├── includes/config.php      DB connection, session, ML bridge, email, schema checks
│   ├── auth.php                 Login, signup, logout, forgot/reset password
│   ├── predict.php              Demand estimate (ML + statistical fallback) + auto-recommendation
│   ├── recommendations.php      List / acknowledge recommendations
│   ├── sales.php                Sales CRUD, CSV import, dashboard stats
│   ├── users.php                Profile self-service, avatar upload, admin user management
│   ├── reports.php              Monthly/yearly aggregates, rolling summary, saved reports
│   └── notifications.php        Notification bell feed
│
├── ml/                           Python inference, called by backend/predict.php
│   ├── ml.py                    Offline training script
│   ├── predict_demand.py        Feature engineering + model.predict()
│   ├── model/sugar_model.pkl    Trained model (not committed — see ml/README.md)
│   ├── requirements.txt
│   └── README.md                ML bridge details, retraining notes
│
├── database/
│   ├── schema.sql                Full schema + sample seed data
│   └── reset_recommendations.sql Clears sample predictions/recommendations
│
└── README.md                    This file
```

## Getting started

### Prerequisites

- PHP 8.x with the `pdo_mysql` and `fileinfo` extensions
- MySQL or MariaDB
- Python 3.10+
- Apache (or another server that can run PHP) — XAMPP is the reference setup

### 1. Database

```bash
mysql -u root -p < database/schema.sql
# Optional: start with an empty predictions/recommendations table
mysql -u root -p sugar_demand_db < database/reset_recommendations.sql
```

### 2. Python / ML environment

```bash
python -m venv .venv
.venv\Scripts\activate          # Windows
# source .venv/bin/activate      # Linux / macOS
pip install -r ml/requirements.txt
```

See [`ml/README.md`](ml/README.md) for how to obtain or train
`ml/model/sugar_model.pkl`. Until a model file exists, `predict.php`
automatically uses the statistical fallback — nothing breaks.

### 3. Web server

Point Apache at the project root so both of these are reachable:

- `http://localhost/sugarcast/frontend/`
- `http://localhost/sugarcast/backend/`

Then open `frontend/index.html` and sign in with a seeded account (see
`database/schema.sql`) or create a new one.

## How a demand estimate becomes a recommendation

1. On **Prediction**, a user submits a target date, price, season, holiday
   flag, and current stock.
2. `backend/predict.php` pulls up to ~400 days of daily sales history and
   calls `runMlPrediction()` (in `includes/config.php`), which shells out
   to `ml/predict_demand.py` with that history over stdin.
3. If there's at least 30 days of continuous history, the trained model
   produces the estimate (`model_used: "ml_linear_regression"`).
   Otherwise, `predict.php` falls back to the statistical formula
   (`model_used: "statistical"`) — the app tells you which method it used,
   and why, rather than pretending it's always the trained model.
4. `predict.php` immediately inserts a matching row into `recommendations`
   (15% safety buffer, risk level from the stock gap).
5. **Recommendation** fetches that list live — stats, the critical-alert
   banner, cards, charts, and history table all reflect real data, with a
   small **ML Model / Statistical** badge on each card showing which
   method produced it.

## API reference

All endpoints live under `backend/` and return JSON. POST requests use
`multipart/form-data`, not JSON bodies. Session-protected endpoints
require a prior `auth.php?action=login`.

| Endpoint               | Key actions                                                                 |
|-------------------------|------------------------------------------------------------------------------|
| `auth.php`              | `login`, `logout`, `check`, `signup`, `forgot_password`, `reset_password`   |
| `predict.php`           | `predict`, `list`, `get`                                                    |
| `recommendations.php`   | `list`, `acknowledge`                                                       |
| `sales.php`             | `list`, `add`, `update`, `delete`, `upload_csv`, `dashboard_stats`          |
| `reports.php`           | `monthly`, `yearly`, `years`, `summary`, `save`, `list`                     |
| `users.php`             | `update_profile`, `change_password`, `upload_avatar`, plus admin `list`/`add`/`update`/`delete`/`toggle_status`/`reset_password` |
| `notifications.php`     | `list`, `mark_read`, `create`                                               |

## Internationalization

Every user-facing string is routed through `data-i18n` attributes or
`I18n.t()` calls, with matching `en` and `sw` entries maintained together
in `assets/js/i18n.js`. Switching language is instant — no reload — via
the toggle in the topbar.

## Design conventions

If you're extending SugarCast, keep these in mind:

- No emojis in the UI — inline Lucide-style SVGs only.
- No AI-themed copy ("Forecast Engine", etc.) — plain business language
  ("Demand Analysis").
- Every new string needs both an `en` and `sw` entry in `i18n.js`.
- `App.api()` always sends `FormData`, not JSON, for POST requests.
- The ML layer is optional by design — anything that touches
  `predict_demand.py` should fail soft into the statistical fallback, not
  break the request.

## Roadmap

- [ ] Multi-market support (currently scoped to Mbeya Central Market)
- [ ] Scheduled/automatic predictions rather than manual submission
- [ ] Accuracy tracking — backfill `actual_demand` and surface model drift
- [ ] Richer model (`sugar_demand_model.pkl`, 31 features) once sales data
      is reliably continuous — see `ml/README.md`

## License

Not yet licensed for public distribution — add a license here before
sharing this repository publicly.
