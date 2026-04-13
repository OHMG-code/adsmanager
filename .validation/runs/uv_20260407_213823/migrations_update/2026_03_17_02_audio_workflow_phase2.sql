CREATE TABLE IF NOT EXISTS spot_audio_dispatches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    spot_id INT NOT NULL,
    dispatched_by_user_id INT NOT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channel VARCHAR(30) NOT NULL DEFAULT 'manual',
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_spot_audio_dispatches_campaign (campaign_id),
    INDEX idx_spot_audio_dispatches_spot (spot_id),
    INDEX idx_spot_audio_dispatches_dispatched_at (dispatched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spot_audio_dispatch_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id INT NOT NULL,
    spot_audio_file_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_spot_audio_dispatch_items_unique (dispatch_id, spot_audio_file_id),
    INDEX idx_spot_audio_dispatch_items_dispatch (dispatch_id),
    INDEX idx_spot_audio_dispatch_items_audio (spot_audio_file_id),
    CONSTRAINT fk_spot_audio_dispatch_items_dispatch
        FOREIGN KEY (dispatch_id) REFERENCES spot_audio_dispatches(id) ON DELETE CASCADE,
    CONSTRAINT fk_spot_audio_dispatch_items_audio
        FOREIGN KEY (spot_audio_file_id) REFERENCES spot_audio_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
