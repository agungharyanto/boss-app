FROM node:22-alpine

# git is required at npm-install time — some transitive dependency (Baileys
# or one of its own deps) is resolved from a git URL, which npm shells out
# to git for; the base alpine image doesn't include it by default.
RUN apk add --no-cache git

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY . .

EXPOSE 3000

CMD ["node", "index.js"]
