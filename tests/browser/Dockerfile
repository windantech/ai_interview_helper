# Headless Chromium + puppeteer-core for UI / responsive / microphone-flow tests
FROM node:22-alpine
RUN apk add --no-cache chromium nss freetype harfbuzz ttf-freefont font-noto
ENV PUPPETEER_SKIP_DOWNLOAD=1 CHROME_PATH=/usr/bin/chromium-browser
WORKDIR /t
RUN npm init -y >/dev/null && npm i puppeteer-core@23 >/dev/null
