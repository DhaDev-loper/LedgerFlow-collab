SET @project_client_id_type = (
    SELECT column_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'clients'
      AND column_name = 'id'
);

SET @create_projects_sql = CONCAT(
    'CREATE TABLE IF NOT EXISTS projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_name VARCHAR(255) NOT NULL,
        client_id ', @project_client_id_type, ' NOT NULL,
        status ENUM(''active'',''archived'') NOT NULL DEFAULT ''active'',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
        UNIQUE KEY uq_projects_name (project_name),
        INDEX idx_projects_status_name (status, project_name),
        INDEX idx_projects_client (client_id)
    ) ENGINE=InnoDB'
);

PREPARE create_projects_statement FROM @create_projects_sql;
EXECUTE create_projects_statement;
DEALLOCATE PREPARE create_projects_statement;

ALTER TABLE transaction_journals
    ADD COLUMN project_id INT UNSIGNED NULL AFTER client_id,
    ADD CONSTRAINT fk_journals_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    ADD INDEX idx_journals_project (project_id);
