<?php
require_once __DIR__ . '/includes/music.php';

function music_sitemap_origin(): string
{
    return 'https://' . music_primary_host();
}

function music_sitemap_public_url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    $basePath = music_base_path();
    if ($basePath !== '') {
        $basePath = '/' . trim($basePath, '/');
    }
    if ($basePath !== '' && strpos($path, $basePath . '/') === 0) {
        $path = substr($path, strlen($basePath));
    } elseif ($basePath !== '' && $path === $basePath) {
        $path = '/';
    }
    return rtrim(music_sitemap_origin(), '/') . $path;
}

function music_sitemap_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function music_sitemap_lastmod(?string $value): string
{
    $time = strtotime((string) $value);
    return $time ? date('Y-m-d', $time) : '';
}

function music_sitemap_item(string $loc, string $lastmod = '', string $changefreq = 'weekly', string $priority = '0.7'): void
{
    static $seen = [];
    if (isset($seen[$loc])) {
        return;
    }
    $seen[$loc] = true;

    echo "  <url>\n";
    echo '    <loc>' . music_sitemap_xml($loc) . "</loc>\n";
    if ($lastmod !== '') {
        echo '    <lastmod>' . music_sitemap_xml($lastmod) . "</lastmod>\n";
    }
    echo '    <changefreq>' . music_sitemap_xml($changefreq) . "</changefreq>\n";
    echo '    <priority>' . music_sitemap_xml($priority) . "</priority>\n";
    echo "  </url>\n";
}

header('Content-Type: application/xml; charset=utf-8');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

music_sitemap_item(music_sitemap_public_url(''), '', 'daily', '1.0');
music_sitemap_item(music_sitemap_public_url(music_artists_url()), '', 'daily', '0.8');
music_sitemap_item(music_sitemap_public_url(music_genres_url()), '', 'daily', '0.8');
music_sitemap_item(music_sitemap_public_url(music_countries_url()), '', 'weekly', '0.7');

if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->query('
            SELECT id, created_at AS lastmod
            FROM song
            WHERE COALESCE(id, "") <> ""
            ORDER BY created_at DESC, id ASC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $song) {
            music_sitemap_item(
                music_sitemap_public_url(music_song_url((string) $song['id'])),
                music_sitemap_lastmod($song['lastmod'] ?? ''),
                'weekly',
                '0.9'
            );
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query('
            SELECT sa.id, sa.name, MAX(s.created_at) AS lastmod
            FROM song_artist sa
            LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
            LEFT JOIN song s ON s.id = sam.song_id
            WHERE COALESCE(sa.name, "") <> ""
            GROUP BY sa.id, sa.name
            ORDER BY sa.name ASC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $artist) {
            music_sitemap_item(
                music_sitemap_public_url(music_artist_url((int) $artist['id'], (string) $artist['name'])),
                music_sitemap_lastmod($artist['lastmod'] ?? ''),
                'weekly',
                '0.8'
            );
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query('
            SELECT g.genre_id, g.title, MAX(s.created_at) AS lastmod
            FROM song_genre g
            LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
            WHERE COALESCE(g.genre_id, "") <> ""
            GROUP BY g.genre_id, g.title
            ORDER BY g.title ASC, g.genre_id ASC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $genre) {
            $genreTitle = trim((string) ($genre['title'] ?? '')) ?: (string) $genre['genre_id'];
            music_sitemap_item(
                music_sitemap_public_url(music_genre_url((string) $genre['genre_id'], $genreTitle)),
                music_sitemap_lastmod($genre['lastmod'] ?? ''),
                'weekly',
                '0.7'
            );
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query('
            SELECT CAST(TRIM(year) AS UNSIGNED) AS song_year, MAX(created_at) AS lastmod
            FROM song
            WHERE TRIM(COALESCE(year, "")) REGEXP "^[0-9]{4}$"
            GROUP BY song_year
            ORDER BY song_year DESC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $year) {
            $songYear = (int) ($year['song_year'] ?? 0);
            if ($songYear > 0) {
                music_sitemap_item(
                    music_sitemap_public_url(music_song_year_url($songYear)),
                    music_sitemap_lastmod($year['lastmod'] ?? ''),
                    'weekly',
                    '0.7'
                );
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query('
            SELECT UPPER(c.lang_country) AS country_code, MAX(s.created_at) AS lastmod
            FROM country c
            LEFT JOIN song s ON LOWER(s.lang) = LOWER(c.lang_key)
            LEFT JOIN song_artist sa ON LOWER(sa.lang_key) = LOWER(c.lang_key)
            WHERE COALESCE(c.lang_country, "") <> ""
            GROUP BY country_code
            HAVING COUNT(DISTINCT s.id) > 0 OR COUNT(DISTINCT sa.id) > 0
            ORDER BY country_code ASC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $country) {
            $countryCode = strtoupper(trim((string) ($country['country_code'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
                music_sitemap_item(
                    music_sitemap_public_url(music_country_url($countryCode)),
                    music_sitemap_lastmod($country['lastmod'] ?? ''),
                    'weekly',
                    '0.7'
                );
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query('
            SELECT slug, lang, COALESCE(NULLIF(updated_at, ""), created_at) AS lastmod
            FROM page
            WHERE COALESCE(slug, "") <> ""
            ORDER BY lastmod DESC
        ');
        foreach ($stmt ? $stmt->fetchAll() : [] as $page) {
            music_sitemap_item(
                music_sitemap_public_url(music_page_url((string) $page['slug'], (string) ($page['lang'] ?? ''))),
                music_sitemap_lastmod($page['lastmod'] ?? ''),
                'monthly',
                '0.6'
            );
        }
    } catch (Throwable $e) {
    }
}

echo "</urlset>\n";
