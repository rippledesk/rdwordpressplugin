const temp = document.createElement("div");
temp.id = "root";
temp.setAttribute("data-source", "wordpress")
const head = document.getElementsByTagName("head")[0];
const body = document.getElementsByTagName("body")[0];
const script = document.createElement("script");
script.src = `${rd_envs.api_url}/rdwidget.min.js?token=${rd_envs.token}&time=${new Date()}`;
script.type = "module";
script.async = true;
script.id = "rippledeskwidget";
body.appendChild(temp);
head.appendChild(script);