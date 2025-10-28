For quick-setup of MySql queue table:

CREATE TABLE queue_tasks (
  id            CHAR(16)      NOT NULL,            -- hex id like 16 bytes
  type          VARCHAR(64)   NOT NULL,
  payload       JSON          NOT NULL,
  status        ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts      INT UNSIGNED  NOT NULL DEFAULT 0,
  available_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reserved_at   DATETIME NULL,
  worker_token  CHAR(16) NULL,
  error         TEXT NULL,
  info          TEXT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_q_lookup (status, available_at),
  KEY idx_q_reserve (status, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

