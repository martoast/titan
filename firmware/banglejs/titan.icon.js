/* App Loader icon source.
 *
 * `"evaluate": true` in titan.app.json tells the App Loader to RUN this file
 * and store whatever it returns as `titan.img`. We build a 48x48 1-bit heart
 * icon at load time with Graphics.createImage (no precomputed/compressed blob
 * to get wrong), so this is safe to flash as-is.
 *
 * If you flash by hand through the Espruino Web IDE you can ignore the icon
 * entirely; the app does not require it to run.
 */
(function () {
  // 48x48, '#' = lit pixel. A simple heart outline so the launcher entry is
  // recognizable.
  var rows = [
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "          ######            ######              ",
    "        ##########        ##########            ",
    "       ############      ############           ",
    "      ##############    ##############          ",
    "     ################  ################         ",
    "    ##################  #################        ",
    "    ##################################          ",
    "    ##################################          ",
    "    ##################################          ",
    "    ##################################          ",
    "     ################################           ",
    "     ################################           ",
    "      ##############################            ",
    "       ############################             ",
    "        ##########################              ",
    "         ########################               ",
    "          ######################                ",
    "           ####################                 ",
    "            ##################                  ",
    "             ################                   ",
    "              ##############                    ",
    "               ############                     ",
    "                ##########                      ",
    "                 ########                       ",
    "                  ######                        ",
    "                   ####                         ",
    "                    ##                          ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                ",
    "                                                "
  ];
  return require("graphics").createImage(rows.join("\n"));
})();
