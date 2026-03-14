# hybrid-dca-bot

Hybrid DCA bot that adjusts a monthly SPY contribution using the 200-day moving
average and the CNN Fear & Greed index, then posts a summary to Telegram and
logs results to CSV.

**Overview**
This script:
1. Pulls SPY daily prices and computes the 200-day moving average.
2. Pulls the latest Fear & Greed value and rating.
3. Calculates a recommended contribution based on trend and sentiment.
4. Tracks a cash buffer so unused contributions roll forward.
5. Sends a Telegram message and appends a CSV record.

**How The Decision Works**
Trend is defined as:
1. Bull: SPY price is above the 200-day moving average.
2. Bear: SPY price is at or below the 200-day moving average.

Contribution rules (USD) are defined in `getInvestmentAmount()` in [main.php](./main.php).

**Requirements**
1. PHP 8.2+
2. cURL extension enabled

**Configuration**
Set these environment variables before running:
1. `TELEGRAM_BOT_TOKEN`
2. `TELEGRAM_CHAT_ID`

These are injected by GitHub Actions in the workflow via repository secrets.

**Run Locally**
```bash
export TELEGRAM_BOT_TOKEN="your_token"
export TELEGRAM_CHAT_ID="your_chat_id"
php main.php
```

**Automation (GitHub Actions)**
The workflow is in [monthly-dca.yml](./.github/workflows/monthly-dca.yml) and:
1. Runs on the 1st of each month at 13:00 UTC.
2. Allows manual runs via the workflow_dispatch event.
3. Commits updated CSV and cash buffer files back to the repo if changed.

**Outputs**
1. `hybrid_dca_history.csv` (monthly log)
2. `cash_buffer.txt` (rollover cash)
3. `fear_greed_cache.json` (cached F&G to handle fetch failures)
4. `fear_greed_debug.json` (debug snapshot when F&G data is malformed)

**Notes**
1. If Telegram credentials are missing, the script prints a warning and exits
   without sending a message.
2. If Fear & Greed fetch fails, the cache is used when available.
