# Multi-stage build — image akhir cuma binary statis, tidak bawa toolchain
# Go (konsisten dengan filosofi container-first/image-lean BOSS-007 yang
# sudah dipakai docker/php, whatsapp-gateway/, dst).
FROM golang:1.26-alpine AS build

WORKDIR /src

COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 go build -o /out/whatsapp-gateway .

FROM alpine:3.20

RUN apk add --no-cache ca-certificates tzdata

WORKDIR /app
COPY --from=build /out/whatsapp-gateway .

EXPOSE 3000

CMD ["./whatsapp-gateway"]
