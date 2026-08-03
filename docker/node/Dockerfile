FROM node:22-bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
