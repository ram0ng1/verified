import app from "flarum/admin/app";

import installGlobalErrorHandler from "../common/utils/installGlobalErrorHandler";
import addHideExtensionSubmitButton from "./addHideExtensionSubmitButton";

export { default as extend } from "./extend";

addHideExtensionSubmitButton();

app.initializers.add("ramon-verified", () => {
  installGlobalErrorHandler();
});
