"""Sugar demand forecasting model training script.

The script prepares historical sugar sales data, compares several regressors
using a chronological split, then saves the model with the lowest holdout RMSE.
"""

from __future__ import annotations

from pathlib import Path

import joblib
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
import seaborn as sns
from sklearn.ensemble import HistGradientBoostingRegressor, RandomForestRegressor
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import GridSearchCV, TimeSeriesSplit


# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
BASE_DIR = Path(__file__).resolve().parent
DATA_FILE = Path(r"D:\NOTES\3rd year\Final Project\Data\new\sugar_dataset1.xlsx")
MODEL_FILE = BASE_DIR / "model" / "sugar_model.pkl"

TARGET_COLUMN = "quantity"
DATE_COLUMN = "date"
PRICE_COLUMN = "price"

LAG_WINDOWS = (1, 7, 14, 30, 60, 90)
ROLLING_WINDOWS = (3, 7, 14)
def load_data(path: Path) -> pd.DataFrame:
    """Load a CSV or Excel dataset."""
    if not path.is_file():
        raise FileNotFoundError(f"Dataset not found: {path}")

    extension = path.suffix.lower()
    if extension == ".csv":
        return pd.read_csv(path)
    if extension in {".xlsx", ".xls"}:
        return pd.read_excel(path, engine="openpyxl")

    raise ValueError("Only CSV, XLSX, and XLS files are supported.")


def validate_columns(data: pd.DataFrame) -> None:
    """Ensure the minimum columns required for training are present."""
    required = {DATE_COLUMN, TARGET_COLUMN, PRICE_COLUMN}
    missing = required.difference(data.columns)
    if missing:
        raise ValueError(f"Dataset is missing required column(s): {', '.join(sorted(missing))}")


def print_dataset_overview(data: pd.DataFrame) -> None:
    """Print a concise dataset summary before processing."""
    print("\n" + "=" * 30)
    print("DATASET INFORMATION")
    print("=" * 30)
    print(f"Rows: {len(data)}")
    print(f"Columns: {len(data.columns)}")
    print("\nColumn names:")
    print(data.columns.tolist())
    print("\nFirst records:")
    print(data.head())
    print("\nMissing values:")
    print(data.isnull().sum())
    print("\nStatistics:")
    print(data.describe())


def clean_data(data: pd.DataFrame) -> pd.DataFrame:
    """Parse dates, order records chronologically, and fill missing values."""
    cleaned = data.copy()
    cleaned[DATE_COLUMN] = pd.to_datetime(cleaned[DATE_COLUMN], errors="raise")
    cleaned = cleaned.sort_values(DATE_COLUMN).drop_duplicates().ffill()
    return cleaned


def show_exploratory_charts(data: pd.DataFrame) -> None:
    """Display demand distribution, trend, and numeric correlation charts."""
    print("\nGenerating exploratory charts...")

    plt.figure(figsize=(10, 5))
    plt.hist(data[TARGET_COLUMN], bins=30)
    plt.title("Sugar Demand Distribution")
    plt.xlabel("Quantity")
    plt.ylabel("Frequency")
    plt.tight_layout()
    plt.show()

    plt.figure(figsize=(14, 6))
    plt.plot(data[DATE_COLUMN], data[TARGET_COLUMN])
    plt.title("Demand Trend")
    plt.xlabel("Date")
    plt.ylabel("Quantity")
    plt.tight_layout()
    plt.show()

    numeric_correlation = data.select_dtypes(include=np.number).corr()
    plt.figure(figsize=(12, 8))
    sns.heatmap(numeric_correlation, annot=True, fmt=".2f")
    plt.title("Correlation Heatmap")
    plt.tight_layout()
    plt.show()


def quantity_outlier_bounds(data: pd.DataFrame) -> tuple[float, float]:
    """Calculate 1.5-IQR quantity bounds from training data only."""
    first_quartile = data[TARGET_COLUMN].quantile(0.25)
    third_quartile = data[TARGET_COLUMN].quantile(0.75)
    interquartile_range = third_quartile - first_quartile
    return (
        first_quartile - 1.5 * interquartile_range,
        third_quartile + 1.5 * interquartile_range,
    )


def remove_quantity_outliers(data: pd.DataFrame, lower_bound: float, upper_bound: float) -> pd.DataFrame:
    """Remove training quantity outliers using pre-calculated IQR bounds."""
    plt.figure()
    plt.boxplot(data[TARGET_COLUMN])
    plt.title("Before Outlier Removal")
    plt.tight_layout()
    plt.show()

    filtered = data[data[TARGET_COLUMN].between(lower_bound, upper_bound)].copy()
    print(f"\nOutliers removed: {len(data) - len(filtered)}")

    plt.figure()
    plt.boxplot(filtered[TARGET_COLUMN])
    plt.title("After Outlier Removal")
    plt.tight_layout()
    plt.show()
    return filtered


def create_features(data: pd.DataFrame) -> pd.DataFrame:
    """Create lag, rolling, momentum, price, seasonality, and trend features."""
    features = data.copy()
    print("\nCreating features...")

    for lag in LAG_WINDOWS:
        features[f"lag_{lag}"] = features[TARGET_COLUMN].shift(lag)

    previous_quantity = features[TARGET_COLUMN].shift(1)
    for window in ROLLING_WINDOWS:
        features[f"roll_{window}"] = previous_quantity.rolling(window).mean()

    features["ema"] = previous_quantity.ewm(span=14).mean()
    features["momentum"] = features[TARGET_COLUMN].shift(1) - features[TARGET_COLUMN].shift(7)
    features["price_change"] = features[PRICE_COLUMN].pct_change()
    features["month"] = features[DATE_COLUMN].dt.month
    features["day_of_week"] = features[DATE_COLUMN].dt.dayofweek
    features["week_of_year"] = features[DATE_COLUMN].dt.isocalendar().week.astype(int)
    features["quarter"] = features[DATE_COLUMN].dt.quarter
    features["is_weekend"] = (features["day_of_week"] >= 5).astype(int)
    features["month_sin"] = np.sin(2 * np.pi * features["month"] / 12)
    features["month_cos"] = np.cos(2 * np.pi * features["month"] / 12)
    features["day_of_week_sin"] = np.sin(2 * np.pi * features["day_of_week"] / 7)
    features["day_of_week_cos"] = np.cos(2 * np.pi * features["day_of_week"] / 7)
    features["trend"] = np.arange(len(features))

    return features.dropna().reset_index(drop=True)


def split_data(data: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Create an 80/20 chronological train/test split."""
    split_index = int(len(data) * 0.80)
    return data.iloc[:split_index].copy(), data.iloc[split_index:].copy()


def split_features_and_target(
    data: pd.DataFrame, test_start: pd.Timestamp
) -> tuple[pd.DataFrame, pd.Series, pd.DataFrame, pd.Series]:
    """Separate predictors and target while preserving the chronological split."""
    predictors = data.drop(columns=[DATE_COLUMN, TARGET_COLUMN])
    target = data[TARGET_COLUMN]
    test_mask = data[DATE_COLUMN] >= test_start

    return (
        predictors.loc[~test_mask],
        target.loc[~test_mask],
        predictors.loc[test_mask],
        target.loc[test_mask],
    )


def evaluate_model(name: str, actual: pd.Series, predicted: np.ndarray) -> float:
    """Print holdout metrics and return RMSE for model selection."""
    mae = mean_absolute_error(actual, predicted)
    rmse = np.sqrt(mean_squared_error(actual, predicted))
    r_squared = r2_score(actual, predicted)

    print(f"\n{name}")
    print(f"MAE: {mae:.2f}")
    print(f"RMSE: {rmse:.2f}")
    print(f"R²: {r_squared:.4f}")
    return rmse


def train_models(
    x_train: pd.DataFrame,
    y_train: pd.Series,
    x_test: pd.DataFrame,
    y_test: pd.Series,
) -> tuple[LinearRegression, RandomForestRegressor, HistGradientBoostingRegressor, str, object, dict[str, np.ndarray]]:
    """Train and compare baseline, forest, and gradient-boosting models."""
    print("\nTraining Linear Regression...")
    linear_model = LinearRegression()
    linear_model.fit(x_train, y_train)
    linear_predictions = linear_model.predict(x_test)
    linear_rmse = evaluate_model("Linear Regression", y_test, linear_predictions)

    print("\nTraining Random Forest...")
    parameter_grid = {
        # Compact search: enough variation to improve the forest without
        # introducing new dependencies or an excessively long training run.
        "n_estimators": [500],
        "max_depth": [12, 20, None],
        "min_samples_split": [2, 5],
        "min_samples_leaf": [1, 2],
        "max_features": [0.8],
    }
    search = GridSearchCV(
        estimator=RandomForestRegressor(random_state=42),
        param_grid=parameter_grid,
        cv=TimeSeriesSplit(n_splits=5),
        scoring="neg_root_mean_squared_error",
        n_jobs=-1,
    )
    search.fit(x_train, y_train)
    random_forest = search.best_estimator_
    forest_predictions = random_forest.predict(x_test)
    forest_rmse = evaluate_model("Random Forest", y_test, forest_predictions)
    print(f"Best Random Forest parameters: {search.best_params_}")

    print("\nTraining Histogram Gradient Boosting...")
    boosting_search = GridSearchCV(
        estimator=HistGradientBoostingRegressor(random_state=42),
        param_grid={
            "learning_rate": [0.03, 0.07, 0.1],
            "max_leaf_nodes": [7, 15, 31],
            "l2_regularization": [0.0, 0.1, 1.0],
        },
        cv=TimeSeriesSplit(n_splits=5),
        scoring="neg_root_mean_squared_error",
        n_jobs=-1,
    )
    boosting_search.fit(x_train, y_train)
    boosting_model = boosting_search.best_estimator_
    boosting_predictions = boosting_model.predict(x_test)
    boosting_rmse = evaluate_model("Histogram Gradient Boosting", y_test, boosting_predictions)
    print(f"Best boosting parameters: {boosting_search.best_params_}")

    scores = {
        "Linear Regression": linear_rmse,
        "Random Forest": forest_rmse,
        "Histogram Gradient Boosting": boosting_rmse,
    }
    models = {
        "Linear Regression": linear_model,
        "Random Forest": random_forest,
        "Histogram Gradient Boosting": boosting_model,
    }
    best_name = min(scores, key=scores.get)
    print(f"\nBest model by holdout RMSE: {best_name} ({scores[best_name]:.2f})")
    return (
        linear_model,
        random_forest,
        boosting_model,
        best_name,
        models[best_name],
        {
            "Linear Regression": linear_predictions,
            "Random Forest": forest_predictions,
            "Histogram Gradient Boosting": boosting_predictions,
        },
    )


def plot_feature_importance(model: RandomForestRegressor, columns: pd.Index) -> None:
    """Plot Random Forest feature importances."""
    importance = pd.DataFrame({
        "Feature": columns,
        "Importance": model.feature_importances_,
    }).sort_values("Importance", ascending=False)

    plt.figure(figsize=(12, 8))
    plt.barh(importance["Feature"], importance["Importance"])
    plt.gca().invert_yaxis()
    plt.title("Random Forest Feature Importance")
    plt.tight_layout()
    plt.show()


def plot_linear_coefficients(model: LinearRegression, columns: pd.Index) -> None:
    """Plot absolute Linear Regression coefficient magnitudes."""
    importance = pd.DataFrame({
        "Feature": columns,
        "Importance": np.abs(model.coef_),
    }).sort_values("Importance", ascending=False)

    plt.figure(figsize=(12, 8))
    plt.barh(importance["Feature"], importance["Importance"])
    plt.gca().invert_yaxis()
    plt.title("Linear Regression Feature Importance")
    plt.tight_layout()
    plt.show()


def plot_predictions(actual: pd.Series, predictions: dict[str, np.ndarray]) -> None:
    """Plot holdout actual values against every evaluated model."""
    plt.figure(figsize=(14, 6))
    plt.plot(actual.to_numpy(), label="Actual")
    for name, predicted in predictions.items():
        plt.plot(predicted, label=name)
    plt.legend()
    plt.title("Actual vs Predicted")
    plt.tight_layout()
    plt.show()


def recommend(demand: float, price: float) -> str:
    """Return a simple stock recommendation for the predicted demand."""
    if demand >= 140:
        return "Increase stock"
    if demand >= 90:
        return "Maintain stock"
    return "Reduce stock"


def main() -> None:
    """Run the complete training and evaluation workflow."""
    data = load_data(DATA_FILE)
    validate_columns(data)
    print_dataset_overview(data)

    data = clean_data(data)
    show_exploratory_charts(data)
    raw_train, raw_test = split_data(data)
    lower_bound, upper_bound = quantity_outlier_bounds(raw_train)
    train_data = remove_quantity_outliers(raw_train, lower_bound, upper_bound)
    # Keep every test observation. It represents future demand, including peaks.
    data = create_features(pd.concat([train_data, raw_test], ignore_index=True))

    x_train, y_train, x_test, y_test = split_features_and_target(data, raw_test[DATE_COLUMN].min())
    linear_model, forest_model, boosting_model, best_name, best_model, predictions = train_models(
        x_train, y_train, x_test, y_test
    )

    print("\nGenerating feature importance charts...")
    plot_feature_importance(forest_model, x_train.columns)
    plot_linear_coefficients(linear_model, x_train.columns)
    plot_predictions(y_test, predictions)

    MODEL_FILE.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(best_model, MODEL_FILE)
    print(f"\nSaved {best_name} model to: {MODEL_FILE}")

    predicted_demand = predictions[best_name][0]
    predicted_price = x_test.iloc[0][PRICE_COLUMN]
    print("\n" + "=" * 20)
    print("RECOMMENDATION")
    print("=" * 20)
    print(f"Demand: {predicted_demand:.0f}")
    print(f"Price: {predicted_price}")
    print(recommend(predicted_demand, predicted_price))
    print("\nPROJECT COMPLETED")


if __name__ == "__main__":
    main()
