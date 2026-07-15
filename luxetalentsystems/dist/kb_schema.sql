USE luxe_talent;

CREATE TABLE IF NOT EXISTS knowledgebase (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(100) NOT NULL DEFAULT 'General',
    subcategory VARCHAR(100) NULL,
    title       VARCHAR(255) NOT NULL,
    file_type   VARCHAR(20)  NOT NULL COMMENT 'pdf, docx, image, link',
    file_path   VARCHAR(500) NULL COMMENT 'path on disk for uploads',
    file_url    VARCHAR(1000) NULL COMMENT 'URL for links',
    file_size   INT NULL,
    content_text LONGTEXT NULL COMMENT 'extracted text for search',
    thumbnail   VARCHAR(500) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category, subcategory),
    FULLTEXT idx_search (title, content_text, category, subcategory)
) ENGINE=InnoDB;
