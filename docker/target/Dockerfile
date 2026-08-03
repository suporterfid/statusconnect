FROM node:22-bookworm-slim

WORKDIR /app

COPY docker/target/server.js ./

EXPOSE 8080

CMD ["node", "server.js"]
