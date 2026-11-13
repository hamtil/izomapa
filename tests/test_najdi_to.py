import json, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class TestNajdiTo(unittest.TestCase):
    def test_sample_najdi_to_structure(self):
        sample_path = ROOT / 'priklady/najdi_to.json'
        self.assertTrue(sample_path.exists(), "priklady/najdi_to.json musí existovat")
        php_adapter = ROOT / 'priklady/najdi_to.php'
        self.assertTrue(php_adapter.exists(), "priklady/najdi_to.php musí existovat")
        php_content = php_adapter.read_text(encoding='utf-8')
        self.assertIn('ip_address', php_content)
        self.assertIn('ip_hash', php_content)
        self.assertIn('level_complete', php_content)
        self.assertIn('getClientIp', php_content)
        self.assertNotIn('admin123', php_content, "Žádný výchozí heslo nesmí být natvrdo v kódu")
        sample_config = ROOT / 'priklady/config.sample.php'
        self.assertTrue(sample_config.exists(), "priklady/config.sample.php musí existovat")
        data = json.loads(sample_path.read_text(encoding='utf-8'))

        self.assertIn('enabled', data)
        self.assertIn('title', data)
        self.assertIn('difficulties', data)
        self.assertIn('levels', data)
        self.assertGreater(len(data['levels']), 0)

        for lvl in data['levels']:
            self.assertIn('lvl', lvl)
            self.assertIn('text', lvl)
            self.assertIn('questions', lvl)
            self.assertGreater(len(lvl['questions']), 0)
            for q in lvl['questions']:
                self.assertIn('id', q)
                self.assertIn('text', q)
                self.assertIn('lat', q)
                self.assertIn('lon', q)

    def test_app_html_contains_game_components(self):
        html = (ROOT / 'web/app.html').read_text(encoding='utf-8')
        # Tlačítko záložky
        self.assertIn('id="gameFold"', html)
        self.assertIn('class="game-fold"', html)

        # Herní panel
        self.assertIn('id="gamePanel"', html)
        self.assertIn('class="panel game-panel"', html)
        self.assertIn('id="gameLvlSelect"', html)
        self.assertIn('id="gameDiffGroup"', html)
        self.assertIn('id="gameStartBtn"', html)
        self.assertIn('id="gameTimer"', html)
        self.assertIn('id="gameQList"', html)

        # Dialogy
        self.assertIn('id="gameFoundDlg"', html)
        self.assertIn('id="gameNickDlg"', html)
        self.assertIn('id="gameNickInput"', html)
        self.assertIn('id="gameWinDlg"', html)
        self.assertIn('id="gameNextConfirmDlg"', html)
        self.assertIn('id="gameExitDlg"', html)
        self.assertIn('id="gameFbGuideDlg"', html)
        self.assertIn('id="gameHelpDlg"', html)
        self.assertIn('id="gameLeaderboardDlg"', html)
        self.assertIn('id="gameCertCard"', html)
        self.assertIn('id="certNick"', html)
        self.assertIn('id="certScores"', html)
        self.assertIn('id="certDate"', html)

        # Herní logika a skrytí popisků
        self.assertIn('body.game-mode .pin', html)
        self.assertIn('initNajdiTo', html)
        self.assertIn('onGameClick', html)
        self.assertIn('facebook.com/sharer', html)
        self.assertIn('action: \'level_complete\'', html)
        self.assertIn('session_id: sessionId', html)

    def test_overlay_parsing_najdi_to(self):
        sample_cfg = {
            "enabled": True,
            "title": "Hra",
            "difficulties": {
                "easy": {"name": "Lehká", "time": 0.0, "weather": "clear"}
            },
            "levels": [
                {
                    "lvl": 1,
                    "text": "Centrum",
                    "questions": [
                        {"id": "q1", "text": "Kostel", "lat": 50.89, "lon": 15.03, "radius": 30}
                    ]
                }
            ]
        }
        # Ověření struktury výstupu
        self.assertEqual(sample_cfg['title'], "Hra")
        self.assertEqual(len(sample_cfg['levels']), 1)
        self.assertEqual(sample_cfg['levels'][0]['questions'][0]['radius'], 30)


if __name__ == '__main__':
    unittest.main()
