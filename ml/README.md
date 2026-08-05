# SugarCast — ML Integration

This folder is what turns `predict.php` from a hand-tuned formula into a
real prediction backed by a trained model.

```
ml/
├── ml.py                 ← training script (run offline, produces model/sugar_model.pkl)
├── predict_demand.py     ← inference script, called by backend/predict.php
├── model/
│   └── sugar_model.pkl   ← trained model, not committed (see "Getting a model file" below)
└── requirements.txt
```

## How it fits together

1. `backend/predict.php` (action=`predict`) pulls up to ~400 days of
   daily-aggregated sales history from `sugar_sales` for the market before
   the target date.
2. It calls `runMlPrediction()` (in `backend/includes/config.php`), which
   shells out to `ml/predict_demand.py` via `proc_open`, sending that
   history plus the form inputs as one JSON object over stdin.
3. `predict_demand.py` builds a continuous daily series (gap-filling the
   same way the `ml.py` training pipeline does: `ffill`/`bfill`), engineers
   the same 19 features the model was trained on, loads `sugar_model.pkl`,
   and prints a JSON prediction to stdout.
4. If there's **not yet 30 days of continuous history**, or the script fails
   for any reason (missing Python, missing dependencies, corrupt model
   file), `predict.php` automatically falls back to the statistical
   formula so the app never breaks — it just reports which method it used
   (`model_used: "ml_linear_regression"` vs `"statistical"`) and, on
   fallback, why (`model_meta.fallback_reason`).

This keeps the ML layer optional at runtime: the rest of the system
degrades gracefully if Python or the model file are ever unavailable.

## One-time setup

From the project root:

```bash
python -m venv .venv

# Windows
.venv\Scripts\activate

# Linux / macOS
source .venv/bin/activate

pip install -r ml/requirements.txt
```

`config.php` auto-detects the venv's interpreter at
`<project_root>/.venv/Scripts/python.exe` (Windows) or
`<project_root>/.venv/bin/python` (Linux/macOS). No path editing is needed
unless the venv lives somewhere else — in that case, edit `ML_PYTHON_BIN`
in `backend/includes/config.php`.

## Getting a model file

`ml/model/sugar_model.pkl` is not committed to the repository (trained
model binaries don't belong in source control, and this one is large).
Two ways to get one locally:

- **Train it yourself** — point `DATA_FILE` in `ml.py` at a sales dataset
  (CSV or XLSX with `date`, `quantity`, `price` columns) and run
  `python ml.py`. It compares Linear Regression, Random Forest, and
  Histogram Gradient Boosting on a chronological train/test split and
  saves whichever has the lowest holdout RMSE to `model/sugar_model.pkl`.
- **Copy an existing one** — drop a previously trained `sugar_model.pkl`
  into `ml/model/`. Until a model file is present, `predict.php` simply
  uses the statistical fallback.

## Quick manual test (no PHP needed)

```bash
cd ml
echo '{"target_date":"2026-08-01","price":1.9,"is_holiday":0,"history":[{"date":"2026-06-01","qty":110,"price":1.85}]}' | python predict_demand.py
```

A healthy response looks like:

```json
{"success": true, "predicted_demand": 123.45, "confidence": 88, "model": "sugar_model.pkl (LinearRegression)", "features_used": {...}, "data_quality": {...}}
```

## Why the simpler model (19 features, not 31)

A 31-feature variant (`sugar_demand_model.pkl`) needs rolling **standard
deviations** and deeper lags, which fall apart on sparse or gappy
real-world data. The 19-feature model needs the same basic lag/rolling
structure but tolerates gap-filled days much better. If sales data becomes
reliably continuous going forward, swapping in the richer model later is
just: drop it into `model/`, update `MODEL_PATH` in `predict_demand.py`,
and extend the feature block with `lag_2`, `lag_3`, the `*_std` rolling
columns, `ema_14`, `price_ma7`, `day_sin`/`day_cos`, `quarter`, and `week`.

## Retraining

When more data is available, retrain with `ml.py` and overwrite
`model/sugar_model.pkl` with the new `joblib.dump(...)` output — no code
changes needed as long as the feature set and order stay the same.

## Receipt/ledger photo extraction (`ocr_receipt.py`)

`ocr_receipt.py` is a second, independent bridge used by the Demand
Analysis page's "Scan a receipt" upload. It has nothing to do with the
prediction model — it just reads a photographed receipt or ledger page
with Tesseract OCR and pulls out a best-guess quantity, price, and date so
a trader doesn't have to retype them.

```
backend/sales.php (action=ocr_extract)
        │  proc_open, image path as argv[1]
        ▼
ml/ocr_receipt.py  →  Tesseract OCR  →  regex field extraction  →  JSON
```

It is deliberately assistive rather than authoritative: the raw OCR text
is always returned alongside the guesses, any field it isn't reasonably
confident about comes back as `null`, and nothing is saved to the
database until the trader reviews and confirms the values in the UI.

**Extra one-time setup — Tesseract is a system binary, not a Python
package**, so `pip install -r requirements.txt` alone is not enough:

- **Windows** — install the [UB-Mannheim Tesseract build](https://github.com/UB-Mannheim/tesseract/wiki) and make sure `tesseract.exe` is on your `PATH` (the installer offers to do this).
- **Linux** — `sudo apt-get install tesseract-ocr`
- **macOS** — `brew install tesseract`

If Tesseract isn't installed, `ocr_extract` fails with a clear message
rather than a server error, and the trader can still fill the form in by
hand — the rest of the system is unaffected.

