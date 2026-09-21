-- Encrypted file storage (docs/spec/02-datenmodell.md "Dateien",
-- CLAUDE.md section 5, decision E-06).
--
-- Original photos, generated PDFs and bank statement files never touch the
-- disk or the database in the clear: the content is a
-- secretstream_xchacha20poly1305 stream under a data key of its own, and that
-- key is only stored sealed to the vault public key. Writing therefore needs
-- no secret (the public submission encrypts without one), reading needs an
-- unlocked vault.
--
-- The table is called `file_blob` and not `blob` because BLOB is a reserved
-- word in MySQL/MariaDB - same reason `setting` has a `name` column instead
-- of `key`. Columns keep the short form (`blob_id`), which is not reserved.
--
-- Plaintext columns here are structure only. Anything that would describe the
-- file - MIME type, original file name, pixel size, page count - lives
-- encrypted in `meta_enc`, because a file name like
-- "Rechnung_Getraenkemarkt_Mueller.pdf" is business data.
CREATE TABLE file_blob (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- `db` | `fs` as VARCHAR and not ENUM: the authority is the PHP enum
    -- App\Domain\BlobStorage, and both backends stay switchable (M2-5).
    storage VARCHAR(8) NOT NULL,
    -- Storage backend `fs` only: the random name below shared/var/blobs/.
    -- Never derived from the uploaded file name - a directory listing must
    -- not tell anyone what a file is.
    fs_name VARCHAR(64) NULL,
    -- Length of the plaintext in bytes, for display and for the size limits
    -- of the upload component (M2-4).
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- SHA-256 over the ciphertext, for the integrity check after switching
    -- backends (M2-5). NULL means the stream was never finished: a write
    -- that broke off halfway leaves a draft row, not a readable blob.
    cipher_sha256 BINARY(32) NULL,
    -- Vault version (1 byte) | box_seal(DEK, VK_pub) = 1 + 48 + 32 = 81 bytes.
    dek_sealed VARBINARY(96) NOT NULL,
    -- Version (1 byte) | secretstream header (24 bytes).
    header VARBINARY(32) NOT NULL,
    -- FieldCipher under the blob's own DEK, AAD "file_blob|<id>|meta_enc".
    -- Room for a long original file name plus nonce and tag.
    meta_enc VARBINARY(2048) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_fs_name (fs_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Storage backend `db`: the ciphertext stream cut into rows. MEDIUMBLOB and
-- chunks well below 1 MiB keep both max_allowed_packet and the memory of a
-- single request small - a chunk is read and handed on one at a time, the
-- whole file is never in memory.
CREATE TABLE file_blob_chunk (
    blob_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    data MEDIUMBLOB NOT NULL,
    PRIMARY KEY (blob_id, seq),
    CONSTRAINT fk_file_blob_chunk_blob FOREIGN KEY (blob_id)
        REFERENCES file_blob (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
