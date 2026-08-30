-- ============================================================
-- Running Man Archive — Reference Data (Seed)
-- Run AFTER schema.sql
-- ============================================================
SET NAMES utf8mb4;

-- Years 2010–2028
INSERT IGNORE INTO years (year_label, total_eps) VALUES
(2010,0),(2011,0),(2012,0),(2013,0),(2014,0),(2015,0),
(2016,0),(2017,0),(2018,0),(2019,0),(2020,0),(2021,0),
(2022,0),(2023,0),(2024,0),(2025,0),(2026,0),(2027,0),(2028,0);

-- Core locations
INSERT IGNORE INTO locations (name, country, city, is_overseas) VALUES
('Seoul',        'South Korea', 'Seoul',  0),
('Busan',        'South Korea', 'Busan',  0),
('Incheon',      'South Korea', 'Incheon',0),
('Gyeongju',     'South Korea', 'Gyeongju',0),
('Jeju Island',  'South Korea', 'Jeju',   0),
('Tokyo',        'Japan',       'Tokyo',  1),
('Osaka',        'Japan',       'Osaka',  1),
('Beijing',      'China',       'Beijing',1),
('Shanghai',     'China',       'Shanghai',1),
('Macau',        'China',       'Macau',  1),
('Hong Kong',    'China',       'Hong Kong',1),
('Taipei',       'Taiwan',      'Taipei', 1),
('Bangkok',      'Thailand',    'Bangkok',1),
('Ho Chi Minh City','Vietnam',  'HCMC',   1),
('Singapore',    'Singapore',   'Singapore',1),
('Kuala Lumpur', 'Malaysia',    'KL',     1),
('Bali',         'Indonesia',   'Bali',   1),
('Sydney',       'Australia',   'Sydney', 1),
('Los Angeles',  'USA',         'LA',     1),
('New York',     'USA',         'New York',1),
('Paris',        'France',      'Paris',  1),
('Prague',       'Czech Republic','Prague',1),
('Dubai',        'UAE',         'Dubai',  1),
('Fiji',         'Fiji',        'Nadi',   1),
('Malta',        'Malta',       'Valletta',1);

-- Themes
INSERT IGNORE INTO themes (name) VALUES
('Name Tag Race'),
('Hide and Seek'),
('Zombie Race'),
('Spy Mission'),
('Variety Race'),
('Water Race'),
('Mud Race'),
('Night Race'),
('Couple Race'),
('Family Race'),
('Team Race'),
('City Race'),
('Treasure Hunt'),
('Escape Race'),
('Betrayal Race');

-- Common tags
INSERT IGNORE INTO tags (name) VALUES
('name tag race'),('hide and seek'),('spy'),('betrayal'),
('team mission'),('water game'),('night race'),('outdoor'),
('indoor'),('celebrity guest'),('idol'),('comedian'),
('actor'),('singer'),('special edition'),('anniversary'),
('first episode'),('last episode'),('new member'),('member farewell');
