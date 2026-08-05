#!/usr/bin/env python3
# ==========================================================
# predict_demand.py — SugarCast ML inference bridge
#
# Loads the trained LinearRegression model (sugar_model.pkl,
# produced by ml.py) and turns a JSON payload of recent daily
# sales history into a single demand prediction.
#
# Called from backend/predict.php via proc_open. Reads one
# JSON object from stdin, writes one JSON object to stdout.
# Never raises — all failure modes are reported as
# {"success": false, "message": "..."} so PHP can fall back
# to the statistical model cleanly.
# ==========================================================

import sys
import json
import os
import math
from datetime import datetime, timedelta

MODEL_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "model", "sugar_model.pkl")

# Minimum number of distinct calendar days of history required
# before we trust the model's longest lag (lag_30).
MIN_HISTORY_DAYS = 30


def fail(message, **extra):
    out = {"success": False, "message": message}
    out.update(extra)
    print(json.dumps(out))
    sys.exit(0)


def main():
    try:
        raw = sys.stdin.read()
        payload = json.loads(raw)
    except Exception as e:
        fail(f"Invalid input payload: {e}")
        return

    try:
        import pandas as pd
        import numpy as np
        import joblib
    except Exception as e:
        fail(f"Python ML dependencies not available: {e}")
        return

    target_date_str = payload.get("target_date")
    price = payload.get("price")
    is_holiday = int(payload.get("is_holiday", 0))
    is_festive = int(payload.get("is_festive", 0))
    history = payload.get("history", [])  # [{date, qty, price}, ...] sorted or not

    if not target_date_str or price is None:
        fail("target_date and price are required.")
        return

    try:
        target_date = datetime.strptime(target_date_str, "%Y-%m-%d").date()
    except Exception:
        fail("target_date must be in YYYY-MM-DD format.")
        return

    if len(history) == 0:
        fail("No sales history available for this market yet.")
        return

    # ---- Build a continuous daily series (gap-filled, like ml.py) ----
    try:
        hist_df = pd.DataFrame(history)
        hist_df["date"] = pd.to_datetime(hist_df["date"])
        hist_df["qty"] = pd.to_numeric(hist_df["qty"], errors="coerce")
        hist_df["price"] = pd.to_numeric(hist_df["price"], errors="coerce")
        hist_df = hist_df.dropna(subset=["date"]).sort_values("date")
        hist_df = hist_df.groupby("date", as_index=True).agg({"qty": "sum", "price": "mean"})

        real_days = len(hist_df)
        if real_days < 3:
            fail("Not enough recorded sales history yet to run the model.",
                 real_days=real_days)
            return

        full_index = pd.date_range(hist_df.index.min(), pd.Timestamp(target_date) - pd.Timedelta(days=1), freq="D")
        series = hist_df.reindex(full_index)
        filled_before = series["qty"].isna().sum()
        series = series.ffill().bfill()

        span_days = len(series)
        qty = series["qty"]
        last_price = float(series["price"].iloc[-1])

        def at(days_back):
            idx = len(qty) - days_back
            if idx < 0:
                return float(qty.iloc[0])
            return float(qty.iloc[idx])

        previous_day_sales = at(1)
        lag_1 = at(1)
        lag_7 = at(7)
        lag_14 = at(14)
        lag_30 = at(30)
        lag_60 = at(60)
        lag_90 = at(90)

        roll_3 = float(qty.tail(3).mean())
        roll_7 = float(qty.tail(7).mean())
        roll_14 = float(qty.tail(14).mean())
        ema = float(qty.ewm(span=14).mean().iloc[-1])

        momentum = lag_1 - lag_7
        price_change = (float(price) - last_price) / last_price if last_price else 0.0

        day_of_week = target_date.weekday()  # Monday=0 .. Sunday=6
        month = target_date.month
        week_of_year = int(pd.Timestamp(target_date).isocalendar().week)
        quarter = int(pd.Timestamp(target_date).quarter)
        is_weekend = 1 if day_of_week >= 5 else 0
        month_sin = math.sin(2 * math.pi * month / 12)
        month_cos = math.cos(2 * math.pi * month / 12)
        day_of_week_sin = math.sin(2 * math.pi * day_of_week / 7)
        day_of_week_cos = math.cos(2 * math.pi * day_of_week / 7)
        trend = (pd.Timestamp(target_date) - series.index.min()).days

        features_row = {
            "price": float(price),
            "is_holiday": is_holiday,
            "is_festive": is_festive,
            "day_of_week": day_of_week,
            "month": month,
            "week_of_year": week_of_year,
            "quarter": quarter,
            "is_weekend": is_weekend,
            "previous_day_sales": previous_day_sales,
            "lag_1": lag_1,
            "lag_7": lag_7,
            "lag_14": lag_14,
            "lag_30": lag_30,
            "lag_60": lag_60,
            "lag_90": lag_90,
            "roll_3": roll_3,
            "roll_7": roll_7,
            "roll_14": roll_14,
            "ema": ema,
            "momentum": momentum,
            "price_change": price_change,
            "month_sin": month_sin,
            "month_cos": month_cos,
            "day_of_week_sin": day_of_week_sin,
            "day_of_week_cos": day_of_week_cos,
            "trend": trend,
        }

    except Exception as e:
        fail(f"Feature engineering failed: {e}")
        return

    # ---- Load model & predict ----
    try:
        model = joblib.load(MODEL_PATH)
        feature_order = list(getattr(model, "feature_names_in_", features_row.keys()))
        X = pd.DataFrame([features_row])[feature_order]
        prediction = float(model.predict(X)[0])
        prediction = max(prediction, 0.0)
    except Exception as e:
        fail(f"Model prediction failed: {e}")
        return

    # ---- Confidence heuristic ----
    # Penalise how much of the window had to be forward/backward-filled
    # rather than being real recorded data.
    fill_ratio = float(filled_before) / span_days if span_days else 1.0
    confidence = 92 - (fill_ratio * 35) - max(0, (MIN_HISTORY_DAYS - real_days)) * 0.5
    confidence = max(55, min(95, round(confidence)))

    result = {
        "success": True,
        "predicted_demand": round(prediction, 2),
        "confidence": confidence,
        "model": "sugar_model.pkl (LinearRegression)",
        "features_used": {k: round(v, 4) if isinstance(v, float) else v for k, v in features_row.items()},
        "data_quality": {
            "real_days_recorded": int(real_days),
            "days_in_window": int(span_days),
            "days_filled": int(filled_before),
        },
    }
    print(json.dumps(result))


if __name__ == "__main__":
    main()
