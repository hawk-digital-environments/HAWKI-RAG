FROM node:22-alpine AS build
WORKDIR /frontend
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY frontend ./frontend
COPY vite.frontend.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM nginx:alpine
COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /frontend/public/frontend /usr/share/nginx/html
COPY public/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
