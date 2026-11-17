<!-- Source: docs/operator-guide.md; SHA256: b01d6327eb3f533c015d43625aa4bc9696d08db1365119a519902e46e5eae923 -->

<a id="redfish-operator-guide"></a>
# Guía del operador de Redfish

Esta guía cubre la versión de desarrollo de plugin_redfish posterior a los hitos 2 y 3. Todavía no hay una versión etiquetada. CI prueba Cacti 1.2.31 y la rama 1.2.x actual en PHP 8.2 a 8.5, incluidos los flujos de instalación y gráficos. Esas tareas usan BMC de prueba y el servidor de simulación Redfish de DMTF; no validan un fabricante ni una línea de firmware reales.

<a id="before-installing"></a>
## Antes de instalar

- Use Cacti 1.2.31 o posterior y PHP 8.2 o posterior. El complemento rechaza expresamente instalarse o activarse en versiones de PHP anteriores a 8.2.
- Ejecute el sondeador de Cacti con acceso a la red de gestión BMC. El recopilador actual usa HTTPS hacia Redfish por TCP 443. No utiliza IPMI ni UDP 623; IPMI corresponde a una fase posterior.
- Elija una cuenta que pueda leer los recursos recopilados. No use una cuenta administrativa. Los nombres y permisos de roles de cada fabricante aún no se han validado en firmware BMC real; verifique la cuenta contra los recursos que expone su BMC.
- Decida cómo leerán la clave de credenciales el sondeador y el servidor web, y cómo respaldará juntos la base de datos y esa clave.

<a id="install-and-prepare-the-vault"></a>
## Instalar y preparar la bóveda

Instale el complemento en el directorio `plugins/redfish` de Cacti y actívelo mediante Plugin Management. El complemento comprueba el requisito de PHP y crea sus tablas mediante la API de complementos de Cacti. Después de activarlo, abra **Configuration → Settings → Redfish** y ajuste la ruta de la clave si no utiliza `/etc/cacti/redfish-vault.key`.

Cree una clave aleatoria de 32 bytes fuera de la raíz web. Para un archivo hexadecimal:

```sh
openssl rand -hex 32
```

Escriba la salida en la ruta configurada con permisos que permitan leer al servidor web y al sondeador, pero impidan a otros usuarios leerla o sustituirla. Solo root y la cuenta de servicio propietaria de la clave deben poder escribir en el directorio. El complemento no crea ni repara el archivo. Respáldelo junto con la base de datos Cacti: una restauración sin la clave correspondiente no puede descifrar contraseñas guardadas.

Fije el presupuesto del recopilador por debajo del intervalo del sondeador. Los valores predeterminados son 300 segundos de intervalo y 240 de presupuesto. Los BMC incompletos se procesan en una ejecución posterior. Ajuste el intervalo de redescubrimiento, número de procesos, tiempos de conexión y solicitud y límite de respuesta a la red de gestión; la página de ajustes indica el rango y valor predeterminado de cada uno.

<a id="configure-tls-and-register-a-bmc"></a>
## Configurar TLS y registrar un BMC

Mantenga activada la verificación de certificados. Use un paquete CA para una autoridad privada o fije la huella SPKI SHA-256 del BMC. Desactivar la verificación en un BMC omite la comprobación del interlocutor para ese BMC y deja una advertencia en la página de estado; úselo solo como excepción temporal de diagnóstico en una red de gestión aislada.

Cree una credencial con la cuenta de solo lectura y su contraseña. Agregue después el BMC con su nombre DNS o dirección de gestión, puerto HTTPS, credencial y modo TLS. Los archivos de importación deben referenciar credenciales por nombre y no contener contraseñas. Importar CSV es una acción administrativa: revise filas y direcciones de destino antes de importar.

El formulario BMC y la página de estado muestran la recopilación y la antigüedad de los datos. Investigue las advertencias de autenticación, TLS, DNS, tiempo de espera, tamaño de respuesta y datos obsoletos antes de crear umbrales. El código actual no se ha validado en firmware BMC real. La matriz de [research/vendor-matrix.md](../../../research/vendor-matrix.md) registra lo conocido y lo pendiente; no deduzca compatibilidad de firmware a partir de la tarea CI de simulación.

<a id="create-a-device-and-graphs"></a>
## Crear un dispositivo y gráficos

En la página BMC, elija **Create Device** para crear un dispositivo Cacti independiente o **Add Graphs** para vincular las consultas a un servidor existente. Revise el plan antes de confirmar. El complemento no sondea el BMC mediante las comprobaciones de disponibilidad de dispositivos Cacti. El creador necesita acceso administrativo Redfish y permiso de gestión de dispositivos Cacti. Un dispositivo nuevo que el creador no pueda ver se revierte.

Tras aprovisionar, use el flujo de creación de gráficos de Cacti para agregar resúmenes y gráficos por miembro. Un miembro ausente o desaparecido se lee como desconocido (`U`); su gráfico e historial RRD se conservan. Después de actualizar firmware o sustituir componentes, ejecute de nuevo **Add Graphs** para reindexar los miembros actuales. Elimine gráficos obsoletos manualmente solo cuando ya no necesite el historial.

<a id="configure-alerts"></a>
## Configurar alertas

Los valores de resumen y por miembro son fuentes de datos Cacti normales. Cree umbrales Thold para los valores relevantes y use programas Maint para suprimir notificaciones durante trabajos planificados. Elija una duración de umbral de al menos un intervalo del recopilador para que una consulta repetida de la misma lectura en caché no parezca una observación nueva. Mantenga activado el umbral `age` si necesita detectar BMC que dejan de responder. Compruebe el disparo y recuperación de umbrales con sus versiones de Thold y Maint antes de depender de ellos en operación.

<a id="back-up-restore-and-handle-failures"></a>
## Copias de seguridad, restauración y fallos

Respalde como un conjunto de recuperación la base de datos Cacti, su configuración, los archivos `rra/` y el archivo exacto de clave de la bóveda. Guarde la copia de la clave separada de la copia de la base de datos y restrinja el acceso a cada una. Pruebe la restauración en una instancia no productiva antes de confiar en ella. Restaurar solo la base de datos o perder la clave obliga a volver a ingresar las contraseñas BMC; restaurar sin sus RRD pierde el historial de los gráficos.

Para un ensayo de recuperación, cree una instancia aislada con la misma versión del complemento y restaure copias de la base de datos, configuración y archivos `rra/`. Modifique la configuración copiada para usar el host y nombre de la base restaurada. Antes de iniciar Cacti, aplique una regla de red que impida a la instancia de prueba acceder a la base de producción y confirme que un intento de conexión a ese host queda bloqueado. Permita acceso únicamente a la base restaurada.

Instale la clave correspondiente en la ruta configurada, conservando propietario y permisos restrictivos. Antes de contactar con cualquier BMC, use una comprobación PHP temporal solo para administradores que llame a `CredentialStore::resolve()` para una credencial restaurada. Descarte inmediatamente la contraseña devuelta y registre solo éxito o fallo; no imprima ni registre el secreto. No existe una acción de interfaz para esta comprobación exclusiva de credenciales. Registre después un BMC de pruebas dedicado y accesible desde la instancia aislada con credenciales exclusivas de prueba. Mantenga desactivadas e inaccesibles las direcciones BMC de producción; no envíe credenciales productivas al simulador. Registre por separado el resultado de credenciales y la recopilación del BMC de prueba. Confirme que la recopilación termina, que la página de estado indica una lectura actual y que un gráfico restaurado conserva su historial.

Repita después sin la clave disponible: la recopilación debe fallar de forma cerrada, las páginas Cacti deben seguir disponibles y la página de estado debe indicar el fallo de credenciales. Registre versiones de Cacti, PHP, base de datos y complemento, y los resultados; no incluya claves ni contraseñas. Esto es un procedimiento, no un ensayo de recuperación ya realizado.

Si la clave falta, no puede leerse o es inválida, la recopilación falla de forma cerrada; no la sustituya por otra esperando que las credenciales existentes funcionen. Restaure la clave correcta o ingrese de nuevo las credenciales. Si un BMC no está disponible, se conservan las lecturas en caché y se marcan obsoletas; pasan a desconocidas en vez de cero. Revise página de estado, registros del sondeador, DNS, conectividad de gestión, acceso de cuenta y confianza de certificados. No desactive globalmente TLS para sortear un problema de certificado.

Desactivar el complemento detiene la recopilación. La desinstalación conserva las tablas por defecto, aunque una opción permite eliminarlas: exporte primero los metadatos BMC y de credenciales que necesite. Se conservan dispositivos, gráficos y configuración BMC. Quitar el complemento no apaga ni reconfigura ningún BMC.

<a id="compatibility-and-qualification-limits"></a>
## Límites de compatibilidad y validación

Actualmente no se declara compatible ninguna línea real de firmware. La batería de desarrollo incluye estructuras Dell, HPE y Lenovo escritas a mano y el simulador DMTF, pero son entradas de prueba, no validaciones de dispositivos reales. OpenBMC y otros fabricantes también necesitan capturas anonimizadas y pruebas antes de declarar compatibilidad. Consulte [research/vendor-matrix.md](../../../research/vendor-matrix.md) y la incidencia [#19](https://github.com/somethingwithproof/plugin_redfish/issues/19).

El complemento aún no se ha publicado. Siguen pendientes los informes piloto de fase 1, medidas de latencia en BMC reales, ensayos de copia y restauración, y validación de seguridad. No use la versión de desarrollo como prueba de que se ha validado un BMC, firmware, tamaño de despliegue o procedimiento de recuperación concreto.
