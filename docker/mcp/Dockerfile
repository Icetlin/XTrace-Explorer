FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    docker.io \
    docker-cli \
    # Chromium/Playwright runtime dependencies
    libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 \
    libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
    libgbm1 libasound2 libpango-1.0-0 libcairo2 \
    fonts-liberation fonts-unifont \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
 && playwright install chromium

COPY server.py .

EXPOSE 8766

CMD ["python", "server.py"]
