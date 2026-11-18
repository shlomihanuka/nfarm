terraform {
  required_version = ">= 1.6"
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 5.0"
    }
  }
  backend "gcs" {
    bucket = "asc-tfstate"
    prefix = "staging"
  }
}

variable "project_id" { type = string }
variable "region" {
  type    = string
  default = "us-central1"
}

locals {
  service_name = "asc-search-stg"
  db_instance  = "asc-pg-stg"
}

provider "google" {
  project = var.project_id
  region  = var.region
}

# ── Service account (least-privilege) ───────────────────────────────────────

resource "google_service_account" "cloud_sa" {
  account_id   = "asc-cloud-stg"
  display_name = "ASC Cloud Run — staging"
}

resource "google_project_iam_member" "sa_vertex" {
  project = var.project_id
  role    = "roles/aiplatform.user"
  member  = "serviceAccount:${google_service_account.cloud_sa.email}"
}

resource "google_project_iam_member" "sa_sql" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.cloud_sa.email}"
}

resource "google_project_iam_member" "sa_secrets" {
  project = var.project_id
  role    = "roles/secretmanager.secretAccessor"
  member  = "serviceAccount:${google_service_account.cloud_sa.email}"
}

# ── Cloud SQL (pgvector) ─────────────────────────────────────────────────────

resource "google_sql_database_instance" "pg" {
  name             = local.db_instance
  database_version = "POSTGRES_15"
  region           = var.region

  settings {
    tier = "db-f1-micro"

    database_flags {
      name  = "cloudsql.enable_pgvector"
      value = "on"
    }

    backup_configuration {
      enabled = true
    }

    ip_configuration {
      ipv4_enabled    = false
      private_network = google_compute_network.vpc.id
    }
  }

  deletion_protection = false
}

resource "google_sql_database" "asc" {
  name     = "asc"
  instance = google_sql_database_instance.pg.name
}

resource "google_sql_user" "asc_user" {
  name     = "asc_user"
  instance = google_sql_database_instance.pg.name
  password = random_password.db_pass.result
}

resource "random_password" "db_pass" {
  length  = 32
  special = false
}

# Store the DB URL in Secret Manager so Cloud Run can inject it
resource "google_secret_manager_secret" "db_url" {
  secret_id = "asc-db-url-stg"
  replication { auto {} }
}

resource "google_secret_manager_secret_version" "db_url" {
  secret = google_secret_manager_secret.db_url.id
  secret_data = "postgresql://asc_user:${random_password.db_pass.result}@127.0.0.1:5432/asc?host=/cloudsql/${var.project_id}:${var.region}:${local.db_instance}"
}

# ── VPC (Cloud SQL private IP) ───────────────────────────────────────────────

resource "google_compute_network" "vpc" {
  name                    = "asc-vpc-stg"
  auto_create_subnetworks = false
}

resource "google_compute_global_address" "private_ip" {
  name          = "asc-private-ip-stg"
  purpose       = "VPC_PEERING"
  address_type  = "INTERNAL"
  prefix_length = 16
  network       = google_compute_network.vpc.id
}

resource "google_service_networking_connection" "private_vpc" {
  network                 = google_compute_network.vpc.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.private_ip.name]
}

# ── Cloud Run service ────────────────────────────────────────────────────────

resource "google_cloud_run_v2_service" "asc" {
  name     = local.service_name
  location = var.region

  template {
    service_account = google_service_account.cloud_sa.email

    scaling {
      min_instance_count = 0
      max_instance_count = 5
    }

    containers {
      image = "gcr.io/${var.project_id}/asc-search-stg:latest"

      resources {
        limits = {
          cpu    = "1"
          memory = "512Mi"
        }
      }

      env {
        name  = "NODE_ENV"
        value = "staging"
      }
      env {
        name  = "GCP_PROJECT_ID"
        value = var.project_id
      }
      env {
        name  = "GCP_REGION"
        value = var.region
      }
      env {
        name  = "GEMINI_INTENT_MODEL"
        value = "gemini-1.5-flash-002"
      }
      env {
        name  = "GEMINI_RANKING_MODEL"
        value = "gemini-1.5-flash-002"
      }
      env {
        name  = "GEMINI_EMBEDDING_MODEL"
        value = "text-embedding-004"
      }
      env {
        name = "DATABASE_URL"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.db_url.secret_id
            version = "latest"
          }
        }
      }
    }

    vpc_access {
      connector = google_vpc_access_connector.connector.id
      egress    = "PRIVATE_RANGES_ONLY"
    }
  }

  traffic {
    type    = "TRAFFIC_TARGET_ALLOCATION_TYPE_LATEST"
    percent = 100
  }
}

resource "google_vpc_access_connector" "connector" {
  name          = "asc-vpc-cx-stg"
  region        = var.region
  network       = google_compute_network.vpc.name
  ip_cidr_range = "10.8.0.0/28"
}

# ── Outputs ──────────────────────────────────────────────────────────────────

output "cloud_run_url" {
  value = google_cloud_run_v2_service.asc.uri
}

output "db_instance_connection_name" {
  value = "${var.project_id}:${var.region}:${local.db_instance}"
}
