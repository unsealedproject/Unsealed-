#!/bin/bash
# ============================================================
# FCA Platform — Backend Database Setup
# Run this on your Iceland server via SSH
# Sets up case submission storage in ~30 lines
# ============================================================

echo "Setting up FCA backend database..."

# 1. Create directories
mkdir -p /var/www/fca/api
mkdir -p /var/www/fca/data/submissions
chmod 755 /var/www/fca/api
chmod 700 /var/www/fca/data/submissions

# 2. Install PHP (for the simple API endpoint)
apt-get install -y php8.1-fpm php8.1-sqlite3

# 3. Create the submissions database
sqlite3 /var/www/fca/data/submissions.db << 'SQLEOF'
CREATE TABLE IF NOT EXISTS cases (
  id TEXT PRIMARY KEY,
  submitted_at TEXT NOT NULL,
  state TEXT,
  county TEXT,
  court TEXT,
  judge_name TEXT,
  attorney_name TEXT,
  opp_attorney TEXT,
  doc_income REAL,
  court_income REAL,
  support_ordered REAL,
  parenting_pct REAL,
  false_allegations INTEGER,
  gag_order INTEGER,
  num_children INTEGER,
  days_without_children INTEGER,
  description TEXT,
  complaint_types TEXT,
  outcome_score REAL,
  hash TEXT
);

CREATE TABLE IF NOT EXISTS ratings (
  id TEXT PRIMARY KEY,
  entity_type TEXT NOT NULL,
  entity_name TEXT NOT NULL,
  entity_state TEXT,
  overall_rating INTEGER,
  categories TEXT,
  review_text TEXT,
  case_types TEXT,
  year_experienced INTEGER,
  verified INTEGER DEFAULT 0,
  submitted_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS complaints (
  id TEXT PRIMARY KEY,
  case_id TEXT,
  complaint_type TEXT,
  target_name TEXT,
  target_state TEXT,
  filed_at TEXT,
  status TEXT DEFAULT 'filed',
  follow_up_sent INTEGER DEFAULT 0,
  notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_cases_judge ON cases(judge_name);
CREATE INDEX IF NOT EXISTS idx_cases_attorney ON cases(attorney_name);
CREATE INDEX IF NOT EXISTS idx_cases_state ON cases(state);
CREATE INDEX IF NOT EXISTS idx_ratings_entity ON ratings(entity_name, entity_type);
SQLEOF

chmod 660 /var/www/fca/data/submissions.db
chown www-data:www-data /var/www/fca/data/submissions.db

echo "Database created."
