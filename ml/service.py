"""
BlotterCast ML Service
=======================
Flask microservice that implements the thesis's three prediction tasks
using real scikit-learn models trained on live MySQL data, each task
using a single designated algorithm:

  1. Binary incident-occurrence prediction (per zone-day)     -> Random Forest
  2. Multi-class incident-type prediction (zone + day-of-week
     + time-of-day)                                           -> Gradient Boosting
  3. Hotspot risk estimation (spatial risk) per zone,
     7/14-day forecast                                        -> Gradient Boosting

Each task trains exactly one model (no algorithm comparison/selection).
The hotspot forecast is computed 14 days out; the Predictions page's
7/14-day toggle simply slices that same array. For every zone, the
forecast also breaks the daily occurrence probability down by incident
category (using the Task 2 model), which the Predictions page renders as
a per-zone line chart with one line per category.

Run standalone:  python service.py            (listens on :5000)
Or via XAMPP:    configure Apache to proxy /ml/* to this service,
                 or just run it alongside XAMPP (see README).
"""
import os
from datetime import datetime, timedelta

import numpy as np
import pandas as pd
import pymysql
from flask import Flask, jsonify
from flask_cors import CORS
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score

DB_HOST = os.environ.get('BC_DB_HOST', '127.0.0.1')
DB_USER = os.environ.get('BC_DB_USER', 'blottercast')
DB_PASS = os.environ.get('BC_DB_PASS', 'blottercast')
DB_NAME = os.environ.get('BC_DB_NAME', 'blottercast')

ZONES = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5', 'Zone 6', 'Zone 7', 'Zone 8']
CATEGORIES = ['Physical Assault', 'Theft', 'Domestic Dispute', 'Vandalism',
              'Trespassing', 'Drug-Related Activity', 'Public Disturbance', 'Other']

app = Flask(__name__)
CORS(app, supports_credentials=True)


def get_conn():
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASS, database=DB_NAME,
                            cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def load_incidents() -> pd.DataFrame:
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, incident_date AS date, zone_id AS zone, hour, category, status FROM incidents ORDER BY incident_date"
            )
            rows = cur.fetchall()
    finally:
        conn.close()
    df = pd.DataFrame(rows)
    df['date'] = pd.to_datetime(df['date'])
    return df


# ---------------------------------------------------------------
# Feature engineering: one row per (zone, day), matching thesis SOP 1
# ---------------------------------------------------------------
def build_panel(df: pd.DataFrame) -> pd.DataFrame:
    if df.empty:
        return pd.DataFrame()

    start, end = df['date'].min(), df['date'].max()
    all_days = pd.date_range(start, end, freq='D')

    # daily incident count per zone (occurrence target + lag/rolling features)
    counts = (
        df.groupby(['zone', 'date']).size().rename('n').reset_index()
    )
    grid = pd.MultiIndex.from_product([ZONES, all_days], names=['zone', 'date']).to_frame(index=False)
    grid = grid.merge(counts, on=['zone', 'date'], how='left')
    grid['n'] = grid['n'].fillna(0).astype(int)
    grid = grid.sort_values(['zone', 'date']).reset_index(drop=True)

    # barangay-wide daily total (for brgy_prev_day feature)
    brgy_daily = grid.groupby('date')['n'].sum().rename('brgy_n')

    rows = []
    for zone, g in grid.groupby('zone'):
        g = g.sort_values('date').reset_index(drop=True)
        g['lag1'] = g['n'].shift(1).fillna(0)
        g['lag7'] = g['n'].shift(7).fillna(0)
        g['roll7'] = g['n'].shift(1).rolling(7, min_periods=1).mean().fillna(0)
        g['roll30'] = g['n'].shift(1).rolling(30, min_periods=1).mean().fillna(0)

        # days since last incident (recurrence feature from thesis)
        last_seen = None
        days_since = []
        for i, row in g.iterrows():
            if last_seen is None:
                days_since.append(999)
            else:
                days_since.append((row['date'] - last_seen).days)
            if row['n'] > 0:
                last_seen = row['date']
        g['days_since_last'] = days_since

        g['brgy_prev_day'] = g['date'].map(lambda d: brgy_daily.get(d - timedelta(days=1), 0))
        rows.append(g)

    panel = pd.concat(rows, ignore_index=True)
    panel['dow'] = panel['date'].dt.dayofweek  # Mon=0..Sun=6
    panel['is_weekend'] = panel['dow'].isin([5, 6]).astype(int)
    panel['dom'] = panel['date'].dt.day
    panel['is_payday'] = panel['dom'].isin([15, 30, 31, 1]).astype(int)
    panel['month'] = panel['date'].dt.month
    panel['month_sin'] = np.sin(2 * np.pi * panel['month'] / 12)
    panel['month_cos'] = np.cos(2 * np.pi * panel['month'] / 12)
    panel['occurred'] = (panel['n'] > 0).astype(int)

    # drop first 30 days per zone (insufficient lag/rolling history), matching JS engine
    trimmed = [g.iloc[30:] for _, g in panel.groupby('zone')]
    panel = pd.concat(trimmed, ignore_index=True)
    return panel


FEATURE_COLS = ['dow', 'is_weekend', 'is_payday', 'month_sin', 'month_cos',
                 'lag1', 'lag7', 'roll7', 'roll30', 'days_since_last', 'brgy_prev_day']


def make_design_matrix(panel: pd.DataFrame):
    X = pd.get_dummies(panel[['zone'] + FEATURE_COLS], columns=['zone'], prefix='zone')
    y = panel['occurred'].values
    return X, y


# ---------------------------------------------------------------
# Binary-target trainer, reused for both the occurrence task
# (Random Forest) and the hotspot task (Gradient Boosting) — same
# panel/target/evaluation methodology, just a different single model
# instance per task.
# ---------------------------------------------------------------
def train_binary_model(panel: pd.DataFrame, name: str, model):
    """Returns (results, trained_model, meta, (X, y)) for a single binary model."""
    X, y = make_design_matrix(panel)
    n = len(X)
    split = int(n * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y[:split], y[split:]

    model.fit(X_train, y_train)
    proba = model.predict_proba(X_test)[:, 1]

    # threshold tuned on training data for best F1 (imbalanced occurrence task)
    train_proba = model.predict_proba(X_train)[:, 1]
    best_thr, best_f1 = 0.5, -1
    for thr in np.arange(0.1, 0.9, 0.02):
        pred = (train_proba >= thr).astype(int)
        f1 = f1_score(y_train, pred, zero_division=0)
        if f1 > best_f1:
            best_f1, best_thr = f1, thr

    pred = (proba >= best_thr).astype(int)
    auc = roc_auc_score(y_test, proba) if len(set(y_test)) > 1 else 0.5

    results = {name: {
        'accuracy': round(accuracy_score(y_test, pred), 4),
        'precision': round(precision_score(y_test, pred, zero_division=0), 4),
        'recall': round(recall_score(y_test, pred, zero_division=0), 4),
        'f1': round(f1_score(y_test, pred, zero_division=0), 4),
        'auc': round(float(auc), 4),
        'threshold': round(float(best_thr), 2),
    }}

    meta = {
        'trainRows': int(len(X_train)), 'testRows': int(len(X_test)),
        'posRate': round(float(y.mean()), 4), 'featureCols': list(X.columns),
    }
    return results, {name: model}, meta, (X, y)


def best_model(results: dict, key: str = 'f1') -> str:
    """Each task now trains exactly one model, so this just returns its name."""
    return next(iter(results))


def train_occurrence_models(panel: pd.DataFrame):
    """Task 1 — incident occurrence classification: Random Forest."""
    model = RandomForestClassifier(n_estimators=200, max_depth=8, min_samples_leaf=5,
                                    class_weight='balanced', random_state=42, n_jobs=-1)
    return train_binary_model(panel, 'random_forest', model)


def train_hotspot_models(panel: pd.DataFrame):
    """Task 3 — hotspot risk estimation: Gradient Boosting."""
    model = GradientBoostingClassifier(n_estimators=150, max_depth=3, learning_rate=0.1, random_state=42)
    return train_binary_model(panel, 'gradient_boosting', model)


def time_bin(hour: int) -> str:
    if 5 <= hour < 12: return 'morning'
    if 12 <= hour < 17: return 'afternoon'
    if 17 <= hour < 21: return 'evening'
    return 'night'


def train_type_models(df: pd.DataFrame):
    """Task 2 — incident-type prediction: Gradient Boosting (multi-class),
    on zone + day-of-week + time-of-day -> category."""
    d = df.copy()
    d['dow'] = d['date'].dt.dayofweek
    d['tbin'] = d['hour'].apply(time_bin)
    d = d.sort_values('date').reset_index(drop=True)

    X = pd.get_dummies(d[['zone', 'dow', 'tbin']].astype(str))
    y = d['category']

    n = len(d)
    split = int(n * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y.iloc[:split], y.iloc[split:]

    name = 'gradient_boosting'
    model = GradientBoostingClassifier(n_estimators=150, max_depth=3, learning_rate=0.1, random_state=42)
    model.fit(X_train, y_train)
    pred = model.predict(X_test)
    results = {name: {
        'accuracy': round(accuracy_score(y_test, pred), 4),
        'macroPrecision': round(precision_score(y_test, pred, average='macro', zero_division=0), 4),
        'macroRecall': round(recall_score(y_test, pred, average='macro', zero_division=0), 4),
        'macroF1': round(f1_score(y_test, pred, average='macro', zero_division=0), 4),
        'nTest': int(len(y_test)),
    }}
    trained_models = {name: model}

    return results, trained_models, list(X.columns)


def predict_top_category(model, cols, zone: str, dow: int, hour: int):
    tbin = time_bin(hour)
    row = pd.DataFrame([{f'zone_{zone}': 1, f'dow_{dow}': 1, f'tbin_{tbin}': 1}])
    row = row.reindex(columns=cols, fill_value=0)
    proba = model.predict_proba(row)[0]
    idx = int(np.argmax(proba))
    return model.classes_[idx], round(float(proba[idx]), 4)


# ---------------------------------------------------------------
# Task 2 helper: average a zone/day-of-week's category-probability
# vector across the 4 time-of-day bins, so the hotspot forecaster can
# break each forecast day's occurrence probability down by category
# without needing to also guess a specific hour.
# ---------------------------------------------------------------
def category_proba_vector(type_model, type_cols, zone: str, dow: int) -> dict:
    tbins = ['morning', 'afternoon', 'evening', 'night']
    # Build an all-zero frame with the exact training columns first, then flip
    # on only the relevant dummy per row — avoids pandas filling mismatched
    # dict keys (a different tbin_* per row) with NaN instead of 0.
    X = pd.DataFrame(0, index=range(len(tbins)), columns=type_cols, dtype=float)
    for i, tbin in enumerate(tbins):
        for col in (f'zone_{zone}', f'dow_{dow}', f'tbin_{tbin}'):
            if col in X.columns:
                X.loc[i, col] = 1
    proba = type_model.predict_proba(X).mean(axis=0)
    return dict(zip(type_model.classes_, proba))


# ---------------------------------------------------------------
# Hotspot / forecast: roll each zone's features forward N days.
# Always computed out to 14 days so the Predictions page's 7/14-day
# toggle can slice one cached result instead of re-forecasting. Each
# day's occurrence probability is also split across incident
# categories (Task 2 model) for the per-zone category line chart.
# ---------------------------------------------------------------
def forecast_hotspots(panel: pd.DataFrame, model, feature_cols_order, type_model, type_cols,
                       horizon: int = 14):
    latest = panel.sort_values('date').groupby('zone').tail(1).set_index('zone')
    results = {}
    cat_cache = {}

    for zone in ZONES:
        if zone not in latest.index:
            continue
        row = latest.loc[zone]
        lag1, lag7, roll7, roll30 = row['lag1'], row['lag7'], row['roll7'], row['roll30']
        days_since = row['days_since_last']
        last_date = row['date']

        probs = []
        cat_series = {c: [] for c in CATEGORIES}
        for step in range(1, horizon + 1):
            d = last_date + timedelta(days=step)
            dow, dom, month = d.dayofweek, d.day, d.month
            feat = {
                'dow': dow, 'is_weekend': int(dow in [5, 6]), 'is_payday': int(dom in [15, 30, 31, 1]),
                'month_sin': np.sin(2 * np.pi * month / 12), 'month_cos': np.cos(2 * np.pi * month / 12),
                'lag1': lag1, 'lag7': lag7, 'roll7': roll7, 'roll30': roll30,
                'days_since_last': days_since, 'brgy_prev_day': row['brgy_prev_day'],
            }
            for z in ZONES:
                feat[f'zone_{z}'] = 1 if z == zone else 0
            X_step = pd.DataFrame([feat]).reindex(columns=feature_cols_order, fill_value=0)
            p = float(model.predict_proba(X_step)[0, 1])
            probs.append(p)

            cache_key = (zone, dow)
            if cache_key not in cat_cache:
                cat_cache[cache_key] = category_proba_vector(type_model, type_cols, zone, dow)
            cat_probs = cat_cache[cache_key]
            for c in CATEGORIES:
                cat_series[c].append(round(float(p * cat_probs.get(c, 0.0)), 4))

            # roll features forward using predicted probability as pseudo-observation
            lag7 = lag1 if step >= 7 else lag7
            lag1 = p
            roll7 = (roll7 * 6 + p) / 7
            roll30 = (roll30 * 29 + p) / 30
            days_since = 0 if p > 0.5 else days_since + 1

        probs7 = probs[:7]
        mean_p = float(np.mean(probs7))
        p_any = 1 - np.prod([1 - p for p in probs7])
        results[zone] = {
            'meanDailyProb': round(mean_p, 4),
            'pAny7d': round(float(p_any), 4),
            'expectedCount7d': round(float(np.sum(probs7)), 2),
            'expectedCount14d': round(float(np.sum(probs)), 2),
            'dailyProbs': [round(p, 4) for p in probs],
            'categorySeries': cat_series,
            'forecastDates': [(last_date + timedelta(days=s)).strftime('%Y-%m-%d') for s in range(1, horizon + 1)],
        }
    return results


def peak_window(df: pd.DataFrame, zone: str) -> str:
    sub = df[df['zone'] == zone]
    if sub.empty:
        return 'N/A'
    hist = sub['hour'].value_counts().reindex(range(24), fill_value=0)
    best_start, best_sum = 0, -1
    for start in range(24):
        window = [hist[(start + i) % 24] for i in range(4)]
        s = sum(window)
        if s > best_sum:
            best_sum, best_start = s, start
    h1, h2 = best_start, (best_start + 4) % 24
    fmt = lambda h: f"{h % 12 or 12}{'AM' if h < 12 else 'PM'}"
    return f"{fmt(h1)}–{fmt(h2)}"


def trend_14d(df: pd.DataFrame, zone: str) -> str:
    sub = df[df['zone'] == zone]
    if sub.empty:
        return '→'
    end = df['date'].max()
    last14 = sub[sub['date'] > end - timedelta(days=14)].shape[0]
    prev14 = sub[(sub['date'] <= end - timedelta(days=14)) & (sub['date'] > end - timedelta(days=28))].shape[0]
    if last14 > prev14 * 1.15:
        return '↑'
    if last14 < prev14 * 0.85:
        return '↓'
    return '→'


# ---------------------------------------------------------------
# Routes
# ---------------------------------------------------------------
@app.route('/health')
def health():
    return jsonify({'ok': True, 'service': 'blottercast-ml', 'time': datetime.now().isoformat()})


@app.route('/train', methods=['POST'])
def train():
    df = load_incidents()
    if len(df) < 60:
        return jsonify({'error': 'Not enough incidents to train (need 60+)'}), 400

    panel = build_panel(df)

    # Task 1 — occurrence: Random Forest
    occ_results, occ_models, occ_meta, (occ_X, occ_y) = train_occurrence_models(panel)
    # Task 3 — hotspot risk: Gradient Boosting
    hot_results, hot_models, hot_meta, (hot_X, hot_y) = train_hotspot_models(panel)
    # Task 2 — incident type: Gradient Boosting
    type_results, type_models, type_cols = train_type_models(df)

    active_occurrence = best_model(occ_results, 'f1')
    active_hotspot = best_model(hot_results, 'f1')
    active_type = best_model(type_results, 'macroF1')

    hotspots = forecast_hotspots(panel, hot_models[active_hotspot], list(hot_X.columns),
                                  type_models[active_type], type_cols)

    zone_rows = []
    for zone in ZONES:
        if zone not in hotspots:
            continue
        h = hotspots[zone]
        dow_now = datetime.now().weekday()
        top_cat, top_p = predict_top_category(type_models[active_type], type_cols, zone, dow_now, 20)
        zone_rows.append({
            'zone': zone,
            'meanDailyProb': h['meanDailyProb'],
            'expectedCount7d': h['expectedCount7d'],
            'expectedCount14d': h['expectedCount14d'],
            'dailyProbs': h['dailyProbs'],
            'categorySeries': h['categorySeries'],
            'forecastDates': h['forecastDates'],
            'topCategory': top_cat,
            'topCategoryProb': top_p,
            'peakWindow': peak_window(df, zone),
            'trend': trend_14d(df, zone),
        })
    zone_rows.sort(key=lambda r: -r['meanDailyProb'])

    # cache to ml_runs table
    import json as _json
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO ml_runs
                   (record_count, active_occurrence_model, active_type_model, active_hotspot_model,
                    occurrence_metrics_json, type_metrics_json, hotspot_metrics_json, hotspots_json)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s)""",
                (len(df), active_occurrence, active_type, active_hotspot,
                 _json.dumps(occ_results), _json.dumps(type_results), _json.dumps(hot_results),
                 _json.dumps(zone_rows)),
            )
    finally:
        conn.close()

    return jsonify({
        'ok': True,
        'recordCount': int(len(df)),
        'occurrence': {'metrics': occ_results, 'active': active_occurrence, 'meta': occ_meta},
        'type': {'metrics': type_results, 'active': active_type},
        'hotspot': {'metrics': hot_results, 'active': active_hotspot, 'meta': hot_meta},
        'zoneRisk': zone_rows,
        'trainedAt': datetime.now().isoformat(),
    })


@app.route('/latest', methods=['GET'])
def latest():
    """Return the most recently cached training run without retraining."""
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM ml_runs ORDER BY id DESC LIMIT 1")
            row = cur.fetchone()
    finally:
        conn.close()
    if not row:
        return jsonify({'ok': False, 'message': 'No trained model yet. POST /train first.'}), 404
    import json as _json
    return jsonify({
        'ok': True,
        'recordCount': row['record_count'],
        'occurrence': {'metrics': _json.loads(row['occurrence_metrics_json']), 'active': row['active_occurrence_model']},
        'type': {'metrics': _json.loads(row['type_metrics_json']), 'active': row['active_type_model']},
        'hotspot': {'metrics': _json.loads(row['hotspot_metrics_json']), 'active': row['active_hotspot_model']},
        'zoneRisk': _json.loads(row['hotspots_json']),
        'trainedAt': row['trained_at'].isoformat() if hasattr(row['trained_at'], 'isoformat') else str(row['trained_at']),
    })


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)
