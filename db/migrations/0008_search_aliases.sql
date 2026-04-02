-- Migration 0008: Search aliases for fuzzy/synonym matching (FRE-40)
-- UP
CREATE TABLE IF NOT EXISTS search_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(100) NOT NULL,
    aliases TEXT NOT NULL,
    UNIQUE KEY uq_term (term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed music/education term mappings
INSERT INTO search_aliases (term, aliases) VALUES
('beats', 'percussion, rhythm, tempo, beat'),
('notes', 'pitch, notation, music theory, note'),
('rhythm', 'beat, tempo, timing, meter, pulse'),
('melody', 'tune, song, melodic, pitch'),
('harmony', 'chord, chords, harmonic, consonance'),
('tempo', 'speed, bpm, pace, timing'),
('scale', 'scales, key, mode, pitch'),
('chord', 'chords, harmony, triad, voicing'),
('dynamics', 'volume, loud, soft, crescendo, forte, piano'),
('timbre', 'tone, sound quality, color, texture'),
('form', 'structure, arrangement, composition'),
('interval', 'intervals, step, half step, whole step'),
('pitch', 'note, frequency, tone, high, low'),
('measure', 'bar, bars, measures, time signature'),
('key', 'scale, tonal, tonality, major, minor'),
('notation', 'notes, sheet music, score, staff'),
('percussion', 'drums, beat, rhythm, hitting'),
('vocals', 'singing, voice, vocal, singer'),
('instrument', 'instruments, play, playing'),
('composition', 'compose, writing, arrangement'),
('frequency', 'pitch, hertz, hz, vibration'),
('amplitude', 'volume, loudness, intensity'),
('math', 'mathematics, numbers, counting, arithmetic'),
('science', 'scientific, experiment, research'),
('reading', 'literacy, phonics, comprehension')
ON DUPLICATE KEY UPDATE aliases = VALUES(aliases);

-- DOWN
-- DROP TABLE IF EXISTS search_aliases;
